<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Data;

final readonly class EdgeCachePurgeData
{
    /**
     * @param  list<string>  $tags
     * @param  list<string>  $urls
     */
    public function __construct(
        public array $tags = [],
        public array $urls = [],
        public bool $purgeAll = false,
    ) {}
}
