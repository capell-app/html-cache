<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Data;

final readonly class EdgePurgeThrottleStatusData
{
    public function __construct(
        public EdgePurgeThrottleSettingsData $settings,
        public ?string $zone = null,
        public bool $locksAvailable = true,
        public ?EdgePurgeZoneRateData $rate = null,
        public bool $learned = false,
        public EdgePurgePendingStatusData $pending = new EdgePurgePendingStatusData,
        public EdgePurgeDroppedData $dropped = new EdgePurgeDroppedData,
    ) {}

    /**
     * Whether queued purges for the configured driver go through the
     * coalescing, rate-limited pipeline.
     */
    public function isActive(): bool
    {
        return $this->settings->enabled && $this->zone !== null && $this->locksAvailable;
    }

    public function isBacklogged(int $now): bool
    {
        $age = $this->pending->oldestAgeSeconds($now);

        return $age !== null && $age > $this->settings->escalateAfterSeconds;
    }
}
