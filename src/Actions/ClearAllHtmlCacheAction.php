<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\HtmlCache\Data\EdgeCachePurgeData;
use Capell\HtmlCache\Data\HtmlCacheClearResult;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Support\Cache\HtmlCacheDependencyTracking;
use Capell\HtmlCache\Support\Cache\HtmlCacheStore;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsJob;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

/**
 * @method static HtmlCacheClearResult run(bool $awaitEdgePurge = false)
 */
final class ClearAllHtmlCacheAction
{
    use AsFake;
    use AsJob;
    use AsObject;

    public bool $jobDeleteWhenMissingModels = true;

    public function handle(bool $awaitEdgePurge = false): HtmlCacheClearResult
    {
        $result = resolve(HtmlCacheStore::class)->deleteAll();

        if (! $result->successful()) {
            throw new RuntimeException(sprintf(
                'Unable to clear every HTML cache artefact: %s',
                implode(', ', $result->failures()),
            ));
        }

        if (Schema::hasTable((new CachedModelUrl)->getTable())) {
            CachedModelUrl::query()->delete();
        }

        // Every page cached from now on is written with current dependency tracking.
        HtmlCacheDependencyTracking::markCurrent();

        $purge = new EdgeCachePurgeData(purgeAll: true);

        if ($awaitEdgePurge) {
            PurgeEdgeCacheAction::run($purge);
        } else {
            PurgeEdgeCacheAction::dispatchAfterCommit($purge);
        }

        return $result;
    }
}
