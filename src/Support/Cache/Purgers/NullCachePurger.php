<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Cache\Purgers;

use Capell\HtmlCache\Contracts\CachePurger;
use Capell\HtmlCache\Data\EdgeCachePurgeData;

final class NullCachePurger implements CachePurger
{
    public function purge(EdgeCachePurgeData $purge): bool
    {
        return ! filter_var(
            config('capell-html-cache.purge.required', false),
            FILTER_VALIDATE_BOOLEAN,
        );
    }
}
