<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Support\Cache\HtmlCacheStore;
use Lorisleiva\Actions\Concerns\AsJob;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static void run()
 */
final class ClearAllHtmlCacheAction
{
    use AsJob;
    use AsObject;

    public bool $jobDeleteWhenMissingModels = true;

    public function handle(): void
    {
        resolve(HtmlCacheStore::class)->deleteAll();
        CachedModelUrl::query()->delete();
    }
}
