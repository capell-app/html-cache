<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Data;

use Spatie\LaravelData\Data;

final class UncachedPageData extends Data
{
    /**
     * @param  list<string>  $reasons
     * @param  list<string>  $cacheIssues
     */
    public function __construct(
        public readonly int $pageUrlId,
        public readonly string $url,
        public readonly string $site,
        public readonly array $reasons,
        public readonly ?string $refreshStatus,
        public readonly ?string $lastError,
        public readonly ?string $lastCachedAt,
        public readonly int $cachedHits,
        public readonly ?float $lastRenderMilliseconds = null,
        public readonly ?float $hitRate = null,
        public readonly array $cacheIssues = [],
        public readonly ?string $metricsObservedSince = null,
    ) {}
}
