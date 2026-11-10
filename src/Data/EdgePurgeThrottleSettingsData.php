<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Data;

final readonly class EdgePurgeThrottleSettingsData
{
    public function __construct(
        public bool $enabled = true,
        public ?string $store = null,
        public ?string $logChannel = null,
        public int $coalesceWindowSeconds = 2,
        public int $maxUrlsPerRequest = 100,
        public int $maxTagsPerRequest = 100,
        public float $requestsPerSecond = 2.0,
        public int $burst = 5,
        public float $minRequestsPerSecond = 0.05,
        public float $decreaseFactor = 0.5,
        public float $increaseStep = 0.1,
        public int $increaseAfterSuccesses = 10,
        public int $learnedRateTtlSeconds = 604800,
        public int $defaultRetryAfterSeconds = 30,
        public int $maxRetryAfterSeconds = 900,
        public int $failureBackoffSeconds = 30,
        public int $maxBatchesPerDrain = 50,
        public int $maxBatchFailures = 5,
        public int $droppedRecordTtlSeconds = 604800,
        public int $drainRetryUntilSeconds = 7200,
        public bool $escalateToFullPurge = true,
        public int $escalatePendingThreshold = 50,
        public int $escalateAfterSeconds = 900,
    ) {}

    public static function fromConfig(): self
    {
        $prefix = 'capell-html-cache.purge.throttle.';
        $ceiling = max(0.001, self::float($prefix . 'requests_per_second', 2.0));

        return new self(
            enabled: self::boolean($prefix . 'enabled', true),
            store: self::optionalString($prefix . 'store'),
            logChannel: self::optionalString($prefix . 'log_channel'),
            coalesceWindowSeconds: max(0, self::integer($prefix . 'coalesce_window_seconds', 2)),
            maxUrlsPerRequest: max(1, self::integer($prefix . 'max_urls_per_request', 100)),
            maxTagsPerRequest: max(1, self::integer($prefix . 'max_tags_per_request', 100)),
            requestsPerSecond: $ceiling,
            burst: max(1, self::integer($prefix . 'burst', 5)),
            minRequestsPerSecond: min($ceiling, max(0.001, self::float($prefix . 'min_requests_per_second', 0.05))),
            decreaseFactor: min(0.95, max(0.05, self::float($prefix . 'decrease_factor', 0.5))),
            increaseStep: max(0.0, self::float($prefix . 'increase_step', 0.1)),
            increaseAfterSuccesses: max(1, self::integer($prefix . 'increase_after_successes', 10)),
            learnedRateTtlSeconds: max(60, self::integer($prefix . 'learned_rate_ttl_seconds', 604800)),
            defaultRetryAfterSeconds: max(1, self::integer($prefix . 'default_retry_after_seconds', 30)),
            maxRetryAfterSeconds: max(1, self::integer($prefix . 'max_retry_after_seconds', 900)),
            failureBackoffSeconds: max(1, self::integer($prefix . 'failure_backoff_seconds', 30)),
            maxBatchesPerDrain: max(1, self::integer($prefix . 'max_batches_per_drain', 50)),
            maxBatchFailures: max(1, self::integer($prefix . 'max_batch_failures', 5)),
            droppedRecordTtlSeconds: max(60, self::integer($prefix . 'dropped_record_ttl_seconds', 604800)),
            drainRetryUntilSeconds: max(60, self::integer($prefix . 'drain_retry_until_seconds', 7200)),
            escalateToFullPurge: self::boolean($prefix . 'escalate_to_full_purge', true),
            escalatePendingThreshold: max(1, self::integer($prefix . 'escalate_pending_threshold', 50)),
            escalateAfterSeconds: max(1, self::integer($prefix . 'escalate_after_seconds', 900)),
        );
    }

    /**
     * The bucket size allowed at a learned rate: a halved rate also halves the
     * burst, so a recovering zone cannot immediately repeat the burst that
     * the provider refused.
     */
    public function burstAt(float $rate): int
    {
        return max(1, (int) floor($this->burst * min(1.0, $rate / $this->requestsPerSecond)));
    }

    public function clampRetryAfter(?int $seconds): int
    {
        return min($this->maxRetryAfterSeconds, max(1, $seconds ?? $this->defaultRetryAfterSeconds));
    }

    private static function boolean(string $key, bool $default): bool
    {
        return filter_var(config($key, $default), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    private static function integer(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    private static function float(string $key, float $default): float
    {
        $value = config($key, $default);

        return is_numeric($value) ? (float) $value : $default;
    }

    private static function optionalString(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
