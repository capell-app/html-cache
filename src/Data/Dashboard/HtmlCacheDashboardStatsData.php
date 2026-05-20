<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Data\Dashboard;

use Spatie\LaravelData\Data;

final class HtmlCacheDashboardStatsData extends Data
{
    public function __construct(
        public readonly int $pageUrls,
        public readonly int $cachedPageUrls,
        public readonly int $uncachedPageUrls,
        public readonly float $coverageRate,
        public readonly int $trackedCachedUrls,
        public readonly int $stalePending,
        public readonly int $staleFailed,
        public readonly float $cachedTrafficCoverageRate,
    ) {}
}
