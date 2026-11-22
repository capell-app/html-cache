<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\HtmlCache\Support\Cache\PageCache;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Synchronously clear cached pages for the current request origin.
 * Returns false when the cache is unbound or there is no origin directory.
 *
 * @api
 *
 * @method static bool run()
 */
final class ClearCurrentOriginHtmlCacheAction
{
    use AsFake;
    use AsObject;

    public function handle(): bool
    {
        return app()->bound(PageCache::class) && resolve(PageCache::class)->clear();
    }
}
