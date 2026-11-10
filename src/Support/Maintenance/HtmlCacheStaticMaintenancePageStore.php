<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Maintenance;

use Capell\Core\Support\PublicRead\PublicArtefactPermit;
use Capell\Frontend\Contracts\StaticMaintenancePageStore;
use Capell\HtmlCache\Support\Cache\HtmlCachePublicationGuard;
use Capell\HtmlCache\Support\Cache\HtmlCacheStore;
use Closure;
use Override;

final class HtmlCacheStaticMaintenancePageStore implements StaticMaintenancePageStore
{
    public function __construct(private readonly HtmlCacheStore $store, private readonly HtmlCachePublicationGuard $publication) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    #[Override]
    public function exclusive(Closure $callback): mixed
    {
        return $this->publication->exclusive($callback);
    }

    #[Override]
    public function fenceIdentity(): string
    {
        return 'html-cache:' . $this->publication->lockPath();
    }

    #[Override]
    public function exists(string $file): bool
    {
        return $this->store->exists($file);
    }

    #[Override]
    public function path(string $file): ?string
    {
        return $this->store->path($file);
    }

    #[Override]
    public function put(PublicArtefactPermit $permit, string $file, string $contents): void
    {
        $permit->assertActiveFor($this);
        $this->store->put($permit, $file, $contents);
    }
}
