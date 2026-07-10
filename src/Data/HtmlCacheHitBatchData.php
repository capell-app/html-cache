<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Data;

final readonly class HtmlCacheHitBatchData
{
    public function __construct(
        public int $hits,
        public int $bytesServed,
    ) {}

    public function hasHits(): bool
    {
        return $this->hits > 0;
    }
}
