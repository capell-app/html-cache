<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Data\CacheMap;

use Spatie\LaravelData\Data;

final class CacheMapOverviewData extends Data
{
    /**
     * @param  list<CacheMapModelSummaryData>  $modelSummaries
     * @param  list<CacheMapResourceSummaryData>  $topResources
     */
    public function __construct(
        public readonly int $totalUrls,
        public readonly int $totalDependencies,
        public readonly array $modelSummaries,
        public readonly array $topResources,
    ) {}
}
