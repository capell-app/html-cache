<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Data;

use Spatie\LaravelData\Data;

final class HtmlCacheRequestMetricsData extends Data
{
    public function __construct(
        public readonly ?float $lastRenderMilliseconds,
        public readonly ?float $hitRate,
        public readonly int $weightedHits,
        public readonly int $weightedMisses,
        public readonly string $observedSince,
    ) {}
}
