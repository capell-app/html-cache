<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Jobs;

use Capell\HtmlCache\Actions\RefreshOriginStaleCachedUrlAction;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Support\Cache\OriginRefreshLock;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class RefreshOriginStaleCachedUrlJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    private const int LOCK_TTL_SECONDS = 120;

    private const int DEFER_SECONDS = 5;

    private const int DEFER_WHILE_REFRESHING_SECONDS = 30;

    /**
     * Waiting for the origin refresh lock counts as an attempt each time the job
     * is picked up again, so the wait is bounded by time (`retryUntil`), long
     * enough to outlast a deployment refresher's hard cap, and the failure budget
     * is the number of exceptions.
     */
    public int $tries = 0;

    public int $maxExceptions = 3;

    /** Kept below the lock's TTL so a render can never outlive the admission it holds. */
    public int $timeout = 90;

    public function __construct(public readonly string $url) {}

    public function uniqueId(): string
    {
        return CachedModelUrl::hashUrl($this->url);
    }

    public function uniqueFor(): int
    {
        $minutes = config('capell-html-cache.invalidation.processing_timeout_minutes', 15);

        // A deferred job lives until `retryUntil`, so its uniqueness must too, or
        // duplicates would be queued behind it.
        return max((is_numeric($minutes) ? max(1, (int) $minutes) : 15) * 60, $this->waitCeilingSeconds());
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addSeconds($this->waitCeilingSeconds());
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 30, 60];
    }

    public function handle(): void
    {
        // Admission is this one acquisition: exclusive work holds the lock, or every
        // slot is rendering, and a job that cannot take a slot never renders.
        $lock = resolve(OriginRefreshLock::class);
        $owner = 'job:' . Str::uuid();

        if (! $lock->tryAcquireSlot($owner, self::LOCK_TTL_SECONDS)) {
            // A deployment refresher holds the lock for minutes; poll far less often then.
            $this->release($lock->isRefresherHeld() ? self::DEFER_WHILE_REFRESHING_SECONDS : self::DEFER_SECONDS);

            return;
        }

        try {
            RefreshOriginStaleCachedUrlAction::run($this->url);
        } finally {
            $lock->release($owner);
        }
    }

    /**
     * The stale row stays pending, so `capell:html-cache:process-stale` still
     * retries it; this only makes the exhausted queue attempts observable.
     */
    public function failed(?Throwable $exception): void
    {
        Log::warning('HTML cache stale refresh exhausted its queue attempts.', [
            'url_hash' => CachedModelUrl::hashUrl($this->url),
            'exception' => $exception instanceof Throwable ? $exception::class : null,
        ]);
    }

    private function waitCeilingSeconds(): int
    {
        $cap = config('capell-html-cache.origin_stale_while_revalidate.refresher_hard_cap_seconds', 1200);

        return (is_numeric($cap) ? max(1, (int) $cap) : 1200) + 300;
    }
}
