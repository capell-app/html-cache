<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\HtmlCache\Contracts\CachePurger;
use Capell\HtmlCache\Contracts\ReportsEdgePurgeResponses;
use Capell\HtmlCache\Data\EdgePurgeThrottleSettingsData;
use Capell\HtmlCache\Data\EdgePurgeThrottleStatusData;
use Capell\HtmlCache\Support\Cache\EdgePurge\EdgePurgePendingQueue;
use Capell\HtmlCache\Support\Cache\EdgePurge\EdgePurgeRateGovernor;
use Capell\HtmlCache\Support\Cache\EdgePurge\EdgePurgeStateStore;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Report the configured edge zone's learned purge rate and pending backlog
 * for health and diagnostics, without sending anything.
 *
 * @method static EdgePurgeThrottleStatusData run()
 */
final class InspectEdgeCachePurgeThrottleAction
{
    use AsFake;
    use AsObject;

    public function handle(): EdgePurgeThrottleStatusData
    {
        $settings = EdgePurgeThrottleSettingsData::fromConfig();
        $purger = $this->purger();
        $zone = $purger instanceof ReportsEdgePurgeResponses ? $purger->edgePurgeZone() : null;
        $locksAvailable = EdgePurgeStateStore::supportsLocks($settings);

        if ($zone === null || ! $settings->enabled || ! $locksAvailable) {
            return new EdgePurgeThrottleStatusData(settings: $settings, zone: $zone, locksAvailable: $locksAvailable);
        }

        $governor = EdgePurgeRateGovernor::fromSettings($settings);
        $queue = EdgePurgePendingQueue::fromSettings($settings);

        return new EdgePurgeThrottleStatusData(
            settings: $settings,
            zone: $zone,
            locksAvailable: true,
            rate: $governor->state($zone),
            learned: $governor->hasLearned($zone),
            pending: $queue->status($zone),
            dropped: $queue->dropped($zone),
        );
    }

    /**
     * Typed as the contract: the binding picks the driver from runtime config.
     */
    private function purger(): CachePurger
    {
        return resolve(CachePurger::class);
    }
}
