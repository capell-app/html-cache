<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\HtmlCache\Jobs\RefreshOriginStaleCachedUrlJob;
use Capell\HtmlCache\Models\CachedModelUrl;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * @method static void run(string $url)
 */
final class ScheduleOriginStaleCachedUrlRefreshAction
{
    use AsFake;
    use AsObject;

    public function handle(string $url): void
    {
        if (config('capell-html-cache.origin_stale_while_revalidate.enabled', true) !== true) {
            return;
        }

        $connection = config('capell-html-cache.origin_stale_while_revalidate.connection') ?? config('queue.default');

        // Sync, deferred, background and arbitrary failover connections cannot
        // guarantee both durable delivery and execution outside the web worker.
        if (! is_string($connection) || ! in_array(config('queue.connections.' . $connection . '.driver'), [
            'database', 'redis', 'sqs', 'beanstalkd',
        ], true)) {
            return;
        }

        try {
            $interval = config('capell-html-cache.origin_stale_while_revalidate.dispatch_interval_seconds', 30);

            if (! Cache::add(
                'capell-html-cache:origin-refresh:dispatch:' . CachedModelUrl::hashUrl($url),
                true,
                is_numeric($interval) ? max(1, (int) $interval) : 30,
            )) {
                return;
            }

            $job = (new RefreshOriginStaleCachedUrlJob($url))->onConnection($connection)->beforeCommit();
            $uniqueLock = resolve(UniqueLock::class);

            if (! $uniqueLock->acquire($job)) {
                return;
            }

            try {
                // Dispatch immediately so broker failures are caught here,
                // rather than in a pending dispatch or transaction callback.
                Bus::dispatch($job);
            } catch (Throwable $throwable) {
                $uniqueLock->release($job);

                throw $throwable;
            }
        } catch (Throwable $throwable) {
            // The stale row remains durable and unclaimed for a later hit or
            // the stale processor. Keep the cooldown during a broker outage.
            report($throwable);
        }
    }
}
