<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Jobs;

use Capell\HtmlCache\Actions\RefreshOriginStaleCachedUrlAction;
use Capell\HtmlCache\Models\CachedModelUrl;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RefreshOriginStaleCachedUrlJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $url) {}

    public function uniqueId(): string
    {
        return CachedModelUrl::hashUrl($this->url);
    }

    public function uniqueFor(): int
    {
        $minutes = config('capell-html-cache.invalidation.processing_timeout_minutes', 15);

        return (is_numeric($minutes) ? max(1, (int) $minutes) : 15) * 60;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 30, 60];
    }

    public function handle(): void
    {
        RefreshOriginStaleCachedUrlAction::run($this->url);
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
}
