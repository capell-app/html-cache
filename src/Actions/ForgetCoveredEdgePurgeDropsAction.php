<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\HtmlCache\Data\EdgeCachePurgeData;
use Capell\HtmlCache\Data\EdgePurgeThrottleSettingsData;
use Capell\HtmlCache\Support\Cache\EdgePurge\EdgePurgeLogger;
use Capell\HtmlCache\Support\Cache\EdgePurge\EdgePurgePendingQueue;
use Capell\HtmlCache\Support\Cache\EdgePurge\EdgePurgeStateStore;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * After a direct purge succeeds, forget the dropped purges it covered, so the
 * Site Health warning clears once an operator has purged them again.
 *
 * @method static void run(?string $zone, EdgeCachePurgeData $purge)
 */
final class ForgetCoveredEdgePurgeDropsAction
{
    use AsFake;
    use AsObject;

    public function handle(?string $zone, EdgeCachePurgeData $purge): void
    {
        $settings = EdgePurgeThrottleSettingsData::fromConfig();

        if ($zone === null || ! $settings->enabled || ! EdgePurgeStateStore::supportsLocks($settings)) {
            return;
        }

        try {
            EdgePurgePendingQueue::fromSettings($settings)->forgetCoveredDrops($zone, $purge);
        } catch (Throwable $exception) {
            // The purge itself succeeded; a stale warning must not fail it.
            new EdgePurgeLogger($settings)->warning('HTML cache edge purge could not update its dropped-purge record.', [
                'zone' => $zone,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
