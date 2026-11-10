<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Cache\EdgePurge;

use Capell\HtmlCache\Data\EdgePurgeResponseData;
use Capell\HtmlCache\Data\EdgePurgeThrottleSettingsData;
use Capell\HtmlCache\Data\EdgePurgeZoneRateData;
use Capell\HtmlCache\Enums\EdgePurgeOutcome;
use Carbon\CarbonImmutable;

/**
 * Paces purge requests per edge zone with a token bucket shared through the
 * cache store, and learns the zone's rate additively-increase /
 * multiplicatively-decrease from the provider's answers.
 */
final readonly class EdgePurgeRateGovernor
{
    private const string STATE = 'rate';

    public function __construct(
        private EdgePurgeThrottleSettingsData $settings,
        private EdgePurgeStateStore $store,
    ) {}

    public static function fromSettings(EdgePurgeThrottleSettingsData $settings): self
    {
        return new self($settings, EdgePurgeStateStore::fromSettings($settings));
    }

    /**
     * Take one request token for the zone. Returns 0 when the request may be
     * sent now, otherwise the seconds to wait before trying again.
     */
    public function reserve(string $zone): float
    {
        return $this->store->withLock($zone, self::STATE, function () use ($zone): float {
            $now = $this->now();
            $state = $this->refill($this->read($zone, $now), $now);

            if ($state->blockedUntil !== null && $state->blockedUntil > $now) {
                $this->write($zone, $state);

                return $state->blockedUntil - $now;
            }

            if ($state->tokens < 1.0) {
                $this->write($zone, $state);

                return (1.0 - $state->tokens) / $state->requestsPerSecond;
            }

            $this->write($zone, $this->with($state, ['tokens' => $state->tokens - 1.0]));

            return 0.0;
        });
    }

    /**
     * Learn from one provider answer and return the zone's new rate.
     */
    public function record(string $zone, EdgePurgeResponseData $response): EdgePurgeZoneRateData
    {
        return $this->store->withLock($zone, self::STATE, function () use ($zone, $response): EdgePurgeZoneRateData {
            $now = $this->now();
            $state = $this->refill($this->read($zone, $now), $now);
            $base = [
                'last_outcome' => $response->outcome->value,
                'last_status' => $response->status,
                'last_retry_after' => $response->retryAfterSeconds,
                'updated_at' => (int) $now,
            ];

            if ($response->exhaustedRateLimit()) {
                $rate = max($this->settings->minRequestsPerSecond, $state->requestsPerSecond * $this->settings->decreaseFactor);
                $wait = $this->settings->clampRetryAfter($response->requestedWaitSeconds());
                $next = $this->with($state, [
                    ...$base,
                    'rate' => $rate,
                    'tokens' => 0.0,
                    'successes' => 0,
                    'blocked_until' => (int) ceil($now) + $wait,
                ]);
            } elseif ($response->outcome === EdgePurgeOutcome::Succeeded) {
                $successes = $state->successesSinceChange + 1;
                $raise = $successes >= $this->settings->increaseAfterSuccesses && $state->isBelowCeiling();
                $next = $this->with($state, [
                    ...$base,
                    'rate' => $raise
                        ? min($this->settings->requestsPerSecond, $state->requestsPerSecond + $this->settings->increaseStep)
                        : $state->requestsPerSecond,
                    'successes' => $raise || $successes >= $this->settings->increaseAfterSuccesses ? 0 : $successes,
                ]);
            } else {
                // A failure that is not a rate limit says nothing about the
                // rate the provider accepts, so the learned rate is kept.
                $next = $this->with($state, $base);
            }

            $this->write($zone, $next);

            return $next;
        });
    }

    public function state(string $zone): EdgePurgeZoneRateData
    {
        $now = $this->now();

        return $this->refill($this->read($zone, $now), $now);
    }

    /**
     * Whether a rate has been learned for the zone and is still remembered.
     */
    public function hasLearned(string $zone): bool
    {
        return $this->store->get($zone, self::STATE) !== null;
    }

    private function read(string $zone, float $now): EdgePurgeZoneRateData
    {
        return EdgePurgeZoneRateData::fromState($this->store->get($zone, self::STATE) ?? [], $this->settings, $now);
    }

    private function write(string $zone, EdgePurgeZoneRateData $state): void
    {
        $this->store->put($zone, self::STATE, $state->toState(), $this->settings->learnedRateTtlSeconds);
    }

    private function refill(EdgePurgeZoneRateData $state, float $now): EdgePurgeZoneRateData
    {
        $elapsed = max(0.0, $now - $state->refilledAt);

        return $this->with($state, [
            'tokens' => min(
                (float) $this->settings->burstAt($state->requestsPerSecond),
                $state->tokens + ($elapsed * $state->requestsPerSecond),
            ),
            'refilled_at' => $now,
        ]);
    }

    /** @param array<string, float|int|string|null> $changes */
    private function with(EdgePurgeZoneRateData $state, array $changes): EdgePurgeZoneRateData
    {
        return EdgePurgeZoneRateData::fromState([...$state->toState(), ...$changes], $this->settings, $this->now());
    }

    private function now(): float
    {
        return (float) CarbonImmutable::now()->format('U.u');
    }
}
