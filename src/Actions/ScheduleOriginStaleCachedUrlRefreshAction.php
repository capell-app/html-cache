<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\HtmlCache\Jobs\RefreshOriginStaleCachedUrlJob;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Support\Cache\OriginRefreshLock;
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

    /**
     * The queue connection that refreshes stale origin copies outside the web
     * worker, or null when origin stale-while-revalidate cannot run.
     */
    public static function durableConnection(): ?string
    {
        if (config('capell-html-cache.origin_stale_while_revalidate.enabled', true) !== true) {
            return null;
        }

        $connection = config('capell-html-cache.origin_stale_while_revalidate.connection') ?? config('queue.default');

        // Sync, deferred, background and arbitrary failover connections cannot
        // guarantee both durable delivery and execution outside the web worker.
        if (! is_string($connection) || ! in_array(config('queue.connections.' . $connection . '.driver'), [
            'database', 'redis', 'sqs', 'beanstalkd',
        ], true)) {
            return null;
        }

        return $connection;
    }

    public function handle(string $url): void
    {
        $connection = self::durableConnection();

        if ($connection === null) {
            return;
        }

        try {
            // Only a hint to save a queue push while a deployment refresher owns the
            // origin and is refreshing every URL itself. Correctness does not rest
            // on it: the job acquires the lock itself and defers when it cannot.
            if (resolve(OriginRefreshLock::class)->isRefresherHeld()) {
                return;
            }

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
