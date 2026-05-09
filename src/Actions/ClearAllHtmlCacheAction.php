<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Support\Cache\HtmlCacheStore;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static void run()
 */
final class ClearAllHtmlCacheAction
{
    use AsObject;

    public function handle(): void
    {
        resolve(HtmlCacheStore::class)->deleteAll();
        CachedModelUrl::query()->delete();
    }
}
