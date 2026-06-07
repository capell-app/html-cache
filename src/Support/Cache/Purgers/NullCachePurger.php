<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Cache\Purgers;

use Capell\HtmlCache\Contracts\CachePurger;

final class NullCachePurger implements CachePurger
{
    /**
     * @param  list<string>  $surrogateKeys
     */
    public function purge(array $surrogateKeys): bool
    {
        return true;
    }
}
