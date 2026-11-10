<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Data;

use Capell\HtmlCache\Enums\EdgePurgeOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Throwable;

final readonly class EdgePurgeResponseData
{
    private const int MAX_DETAIL_LENGTH = 500;

    public function __construct(
        public EdgePurgeOutcome $outcome,
        public ?int $status = null,
        public ?int $retryAfterSeconds = null,
        public ?int $rateLimitRemaining = null,
        public ?int $rateLimitResetSeconds = null,
        public ?string $detail = null,
    ) {}

    public static function succeeded(): self
    {
        return new self(EdgePurgeOutcome::Succeeded);
    }

    public static function failed(string $detail, ?int $status = null): self
    {
        return new self(EdgePurgeOutcome::Failed, status: $status, detail: self::truncate($detail));
    }

    public static function fromException(Throwable $exception): self
    {
        return self::failed($exception::class . ': ' . $exception->getMessage());
    }

    /**
     * Classify a provider response. A 429, or a 503 that names a Retry-After,
     * is a rate-limit refusal rather than a failure.
     */
    public static function fromResponse(Response $response, bool $accepted): self
    {
        $status = $response->status();
        $retryAfter = self::retryAfterSeconds($response->header('Retry-After'));
        $throttled = $status === 429 || ($status === 503 && $retryAfter !== null);

        return new self(
            outcome: match (true) {
                $throttled => EdgePurgeOutcome::Throttled,
                $accepted => EdgePurgeOutcome::Succeeded,
                default => EdgePurgeOutcome::Failed,
            },
            status: $status,
            retryAfterSeconds: $retryAfter,
            rateLimitRemaining: self::headerInteger($response, ['RateLimit-Remaining', 'X-RateLimit-Remaining']),
            rateLimitResetSeconds: self::headerInteger($response, ['RateLimit-Reset', 'X-RateLimit-Reset']),
            detail: $accepted && ! $throttled ? null : self::truncate($response->body()),
        );
    }

    public function isSuccessful(): bool
    {
        return $this->outcome === EdgePurgeOutcome::Succeeded;
    }

    /**
     * Whether the provider refused the purge request itself as malformed or
     * too large, so splitting or dropping it is the only way forward. Every
     * other failure, including refused credentials, is an outage to wait out.
     */
    public function isBadRequest(): bool
    {
        return $this->outcome === EdgePurgeOutcome::Failed
            && in_array($this->status, [400, 413, 414, 422], true);
    }

    /**
     * Whether the provider refused the configured credentials, such as an
     * expired or rotated token. Purges must wait for new credentials.
     */
    public function isCredentialFailure(): bool
    {
        return $this->outcome === EdgePurgeOutcome::Failed
            && in_array($this->status, [401, 403], true);
    }

    /**
     * Whether the provider says its rate limit is exhausted, either by
     * refusing the request or by reporting no remaining requests.
     */
    public function exhaustedRateLimit(): bool
    {
        return $this->outcome === EdgePurgeOutcome::Throttled || $this->rateLimitRemaining === 0;
    }

    /**
     * How long the provider asked callers to wait, if it said.
     */
    public function requestedWaitSeconds(): ?int
    {
        return $this->retryAfterSeconds ?? ($this->rateLimitRemaining === 0 ? $this->rateLimitResetSeconds : null);
    }

    /** @return array<string, int|string|null> */
    public function toLogContext(): array
    {
        return [
            'outcome' => $this->outcome->value,
            'status' => $this->status,
            'retry_after_seconds' => $this->retryAfterSeconds,
            'rate_limit_remaining' => $this->rateLimitRemaining,
            'rate_limit_reset_seconds' => $this->rateLimitResetSeconds,
            'detail' => $this->detail,
        ];
    }

    private static function retryAfterSeconds(string $value): ?int
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        try {
            return max(0, (int) CarbonImmutable::now()->diffInSeconds(CarbonImmutable::parse($value), false));
        } catch (Throwable) {
            return null;
        }
    }

    /** @param list<string> $names */
    private static function headerInteger(Response $response, array $names): ?int
    {
        foreach ($names as $name) {
            $value = trim($response->header($name));

            if ($value !== '' && ctype_digit($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    private static function truncate(string $detail): ?string
    {
        $detail = trim($detail);

        return $detail === '' ? null : mb_substr($detail, 0, self::MAX_DETAIL_LENGTH);
    }
}
