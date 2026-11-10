<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Data;

/**
 * The learned request rate and token bucket for one edge zone.
 */
final readonly class EdgePurgeZoneRateData
{
    public function __construct(
        public float $requestsPerSecond,
        public float $ceilingRequestsPerSecond,
        public float $tokens,
        public float $refilledAt,
        public int $successesSinceChange = 0,
        public ?int $blockedUntil = null,
        public ?string $lastOutcome = null,
        public ?int $lastStatus = null,
        public ?int $lastRetryAfterSeconds = null,
        public ?int $updatedAt = null,
    ) {}

    /** @param array<string, mixed> $state */
    public static function fromState(array $state, EdgePurgeThrottleSettingsData $settings, float $now): self
    {
        $rate = is_numeric($state['rate'] ?? null) ? (float) $state['rate'] : $settings->requestsPerSecond;

        return new self(
            requestsPerSecond: min($settings->requestsPerSecond, max($settings->minRequestsPerSecond, $rate)),
            ceilingRequestsPerSecond: $settings->requestsPerSecond,
            tokens: is_numeric($state['tokens'] ?? null) ? (float) $state['tokens'] : (float) $settings->burstAt($rate),
            refilledAt: is_numeric($state['refilled_at'] ?? null) ? (float) $state['refilled_at'] : $now,
            successesSinceChange: is_int($state['successes'] ?? null) ? $state['successes'] : 0,
            blockedUntil: is_int($state['blocked_until'] ?? null) ? $state['blocked_until'] : null,
            lastOutcome: is_string($state['last_outcome'] ?? null) ? $state['last_outcome'] : null,
            lastStatus: is_int($state['last_status'] ?? null) ? $state['last_status'] : null,
            lastRetryAfterSeconds: is_int($state['last_retry_after'] ?? null) ? $state['last_retry_after'] : null,
            updatedAt: is_int($state['updated_at'] ?? null) ? $state['updated_at'] : null,
        );
    }

    /** @return array<string, float|int|string|null> */
    public function toState(): array
    {
        return [
            'rate' => $this->requestsPerSecond,
            'tokens' => $this->tokens,
            'refilled_at' => $this->refilledAt,
            'successes' => $this->successesSinceChange,
            'blocked_until' => $this->blockedUntil,
            'last_outcome' => $this->lastOutcome,
            'last_status' => $this->lastStatus,
            'last_retry_after' => $this->lastRetryAfterSeconds,
            'updated_at' => $this->updatedAt,
        ];
    }

    public function isBelowCeiling(): bool
    {
        return $this->requestsPerSecond < $this->ceilingRequestsPerSecond;
    }
}
