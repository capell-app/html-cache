<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\HtmlCache\Contracts\CachePurger;
use Capell\HtmlCache\Contracts\ReportsEdgePurgeResponses;
use Capell\HtmlCache\Data\EdgeCachePurgeData;
use Capell\HtmlCache\Data\EdgePurgeThrottleSettingsData;
use Capell\HtmlCache\Support\Cache\EdgePurge\EdgePurgeLogger;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsJob;
use Lorisleiva\Actions\Concerns\AsObject;
use Lorisleiva\Actions\Decorators\JobDecorator;
use RuntimeException;
use Throwable;

/**
 * @method static void run(EdgeCachePurgeData $purge)
 * @method static mixed dispatch(EdgeCachePurgeData $purge, bool $urlScopedTags = false)
 */
final class PurgeEdgeCacheAction
{
    use AsFake;
    use AsJob;
    use AsObject;

    private const string TAGS_SKIPPED_LOGGED = 'capell-html-cache:edge-purge:url-scoped-tags-skipped';

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [5, 30, 120, 300];

    /** @var list<array{EdgeCachePurgeData, bool}>|null */
    private static ?array $batch = null;

    /**
     * Collect every purge requested while the callback runs and send them as
     * one purge, so withdrawing several URLs costs one edge request batch
     * rather than one per URL. What was collected is sent even when the
     * callback fails part-way.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function batch(Closure $callback): mixed
    {
        if (self::$batch !== null) {
            return $callback();
        }

        self::$batch = [];

        try {
            return $callback();
        } finally {
            // Also on failure: the URLs already withdrawn lost their index rows,
            // so a retry would not find them to purge again.
            $purges = self::$batch;
            self::$batch = null;

            if ($purges !== null && $purges !== []) {
                [$merged, $urlScopedTags] = self::merge($purges);
                self::dispatchAfterCommit($merged, $urlScopedTags);
            }
        }
    }

    /**
     * @param  bool  $urlScopedTags  the tags only widen a URL purge to its variants, so
     *                               they may be dropped when tag purges cannot be paced
     */
    public static function dispatchAfterCommit(EdgeCachePurgeData $purge, bool $urlScopedTags = false): void
    {
        if (self::$batch !== null) {
            self::$batch[] = [$purge, $urlScopedTags];

            return;
        }

        DB::afterCommit(static function () use ($purge, $urlScopedTags): void {
            self::dispatch($purge, $urlScopedTags);
        });
    }

    /**
     * Queued purges join their edge zone's coalesced, rate-paced pending set
     * when the configured purger supports it; otherwise they are sent now.
     * Unpaced, a URL-scoped purge goes out URL-only: tag purges are the
     * provider's scarce allowance, and a burst of them would be refused.
     */
    public function asJob(JobDecorator $job, EdgeCachePurgeData $purge, bool $urlScopedTags = false): void
    {
        if (QueueEdgeCachePurgeAction::make()->forJob($job, $purge)) {
            return;
        }

        if ($urlScopedTags && ! $purge->purgeAll && $purge->tags !== [] && resolve(CachePurger::class) instanceof ReportsEdgePurgeResponses) {
            $this->recordSkippedTags();
            $purge = new EdgeCachePurgeData(urls: $purge->urls);

            if ($purge->urls === []) {
                return;
            }
        }

        $this->handle($purge);
    }

    public function handle(EdgeCachePurgeData $purge): void
    {
        throw_unless(
            resolve(CachePurger::class)->purge($purge),
            RuntimeException::class,
            'Unable to purge the edge HTML cache.',
        );
    }

    /**
     * Tags may only be dropped when every merged purge allowed it.
     *
     * @param  non-empty-list<array{EdgeCachePurgeData, bool}>  $purges
     * @return array{EdgeCachePurgeData, bool}
     */
    private static function merge(array $purges): array
    {
        $tags = [];
        $urls = [];
        $purgeAll = false;
        $urlScopedTags = true;

        foreach ($purges as [$purge, $scoped]) {
            $tags = [...$tags, ...$purge->tags];
            $urls = [...$urls, ...$purge->urls];
            $purgeAll = $purgeAll || $purge->purgeAll;
            $urlScopedTags = $urlScopedTags && $scoped;
        }

        return [new EdgeCachePurgeData(
            tags: array_values(array_unique($tags)),
            urls: array_values(array_unique($urls)),
            purgeAll: $purgeAll,
        ), $urlScopedTags];
    }

    private function recordSkippedTags(): void
    {
        try {
            if (! Cache::add(self::TAGS_SKIPPED_LOGGED, true, 3600)) {
                return;
            }
        } catch (Throwable) {
            // Without a cache, warn on every skip rather than never.
        }

        new EdgePurgeLogger(EdgePurgeThrottleSettingsData::fromConfig())->warning(
            'HTML cache edge purge sent without its tags: tag purges cannot be paced without a shared locking store and a queue worker.',
            ['remediation' => 'Configure capell-html-cache.purge.throttle.store with a shared cache store that supports locks, and run a queue worker.'],
        );
    }
}
