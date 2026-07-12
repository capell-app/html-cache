<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Error;

use Capell\Frontend\Contracts\StaticErrorPageStore;
use Capell\HtmlCache\Support\Cache\HtmlCacheStore;

final class HtmlCacheStaticErrorPageStore implements StaticErrorPageStore
{
    public function __construct(private readonly HtmlCacheStore $store) {}

    public function exists(string $file): bool
    {
        return $this->store->exists($file);
    }

    public function path(string $file): ?string
    {
        return $this->store->path($file);
    }

    public function put(string $file, string $contents): void
    {
        $this->store->put($file, $contents);
    }
}
