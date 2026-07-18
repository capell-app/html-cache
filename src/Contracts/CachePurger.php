<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Contracts;

use Capell\HtmlCache\Data\EdgeCachePurgeData;

interface CachePurger
{
    public function purge(EdgeCachePurgeData $purge): bool;
}
