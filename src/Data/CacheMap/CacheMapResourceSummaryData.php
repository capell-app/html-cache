<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Data\CacheMap;

use Spatie\LaravelData\Data;

final class CacheMapResourceSummaryData extends Data
{
    public function __construct(
        public readonly string $key,
        public readonly string $modelType,
        public readonly string $modelLabel,
        public readonly int $resourceId,
        public readonly string $label,
        public readonly int $dependencyCount,
        public readonly int $urlCount,
    ) {}
}
