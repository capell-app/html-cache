<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\HtmlCache\Data\HtmlCacheClearResult;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Support\Cache\HtmlCacheStore;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsJob;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static HtmlCacheClearResult run()
 */
final class ClearAllHtmlCacheAction
{
    use AsFake;
    use AsJob;
    use AsObject;

    public bool $jobDeleteWhenMissingModels = true;

    public function handle(): HtmlCacheClearResult
    {
        $result = resolve(HtmlCacheStore::class)->deleteAll();

        if ($result->successful()) {
            CachedModelUrl::query()->delete();
        }

        return $result;
    }
}
