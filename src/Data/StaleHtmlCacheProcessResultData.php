<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Data;

final class StaleHtmlCacheProcessResultData
{
    public function __construct(
        public int $attempted = 0,
        public int $succeeded = 0,
        public int $failed = 0,
        public int $deferred = 0,
        public int $notApplicable = 0,
    ) {}

    public function successful(): bool
    {
        return $this->failed === 0;
    }
}
