<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\HtmlCache\Contracts\CachePurger;
use Capell\HtmlCache\Contracts\ReportsEdgePurgeResponses;
use Capell\HtmlCache\Data\EdgeCachePurgeData;
use Capell\HtmlCache\Data\EdgePurgeThrottleSettingsData;
use Capell\HtmlCache\Support\Cache\EdgePurge\EdgePurgeLogger;
use Capell\HtmlCache\Support\Cache\EdgePurge\EdgePurgePendingQueue;
use Capell\HtmlCache\Support\Cache\EdgePurge\EdgePurgeStateStore;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Queue;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Lorisleiva\Actions\Decorators\JobDecorator;

/**
 * Add a purge to its edge zone's pending set, so a burst of purges is sent
 * as a few provider-sized batches by one paced drain job instead of one
 * request per purge.
 *
 * @method static bool run(EdgeCachePurgeData $purge)
 */
final class QueueEdgeCachePurgeAction
{
    use AsFake;
    use AsObject;

    /**
     * Queue a purge from inside a queued job. Returns false when the purge
     * must be sent directly; a busy pending set releases the job briefly.
     */
    public function forJob(JobDecorator $job, EdgeCachePurgeData $purge): bool
    {
        try {
            return $this->handle($purge);
        } catch (LockTimeoutException) {
            $job->release(1);

            return true;
        }
    }

    /**
     * Returns false when the configured purger cannot be throttled, so the
     * caller sends the purge directly as before.
     */
    public function handle(EdgeCachePurgeData $purge): bool
    {
        $settings = EdgePurgeThrottleSettingsData::fromConfig();
        $purger = $this->purger();

        if (! $settings->enabled || ! $purger instanceof ReportsEdgePurgeResponses) {
            return false;
        }

        $zone = $purger->edgePurgeZone();

        if ($zone === null || ! EdgePurgeStateStore::supportsLocks($settings) || $this->drainsSynchronously()) {
            return false;
        }

        $purge = new EdgeCachePurgeData(
            tags: $purge->tags,
            urls: array_values(array_filter($purge->urls, $this->isPurgeableUrl(...))),
            purgeAll: $purge->purgeAll,
        );

        if (! $purge->purgeAll && $purge->urls === [] && $purge->tags === []) {
            return true;
        }

        $result = EdgePurgePendingQueue::fromSettings($settings)->add($zone, $purge);

        if ($result->escalationReason !== null) {
            new EdgePurgeLogger($settings)->warning('HTML cache edge purge escalated to a full-zone purge.', [
                'zone' => $zone,
                'reason' => $result->escalationReason,
                'threshold' => $settings->escalatePendingThreshold,
            ]);
        }

        if ($result->drainerToken !== null) {
            DrainEdgeCachePurgeQueueAction::dispatch($zone, $result->drainerToken)
                ->delay($settings->coalesceWindowSeconds);
        }

        return true;
    }

    private function isPurgeableUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    /**
     * On the sync queue, and on drivers built on it (deferred, background),
     * a delayed drain job runs at once and can never wait for the provider,
     * so purges are sent directly there instead.
     */
    private function drainsSynchronously(): bool
    {
        $connection = config('queue.default');

        return Queue::connection(is_string($connection) ? $connection : null) instanceof SyncQueue;
    }

    /**
     * Typed as the contract: the binding picks the driver from runtime config.
     */
    private function purger(): CachePurger
    {
        return resolve(CachePurger::class);
    }
}
