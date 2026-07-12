<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Contracts;

interface CachePurger
{
    /**
     * @param  list<string>  $surrogateKeys
     */
    public function purge(array $surrogateKeys): bool;
}
