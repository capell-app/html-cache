<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Cache;

use Capell\Frontend\Contracts\FrontendOutputCacheInvalidator;
use Capell\HtmlCache\Actions\ClearAllHtmlCacheAction;
use Capell\HtmlCache\Actions\ExpireOverdueStaleCachedUrlsAction;
use Capell\HtmlCache\Actions\MarkAllCachedUrlsStaleAction;
use Capell\HtmlCache\Actions\ScheduleOriginStaleCachedUrlRefreshAction;
use Override;

/**
 * Site-wide output changes (critical CSS promotion, theme settings) do not
 * withdraw any URL, so when stale copies can be refreshed outside the request
 * they are stale-marked and kept (only while a worker heartbeat proves one is
 * polling the refresh connection; otherwise everything is cleared): visitors get the previous copy while the
 * refresh runs, instead of every page becoming a cold inline render and
 * already-refreshed rows losing their files.
 */
final class HtmlFrontendOutputCacheInvalidator implements FrontendOutputCacheInvalidator
{
    #[Override]
    public function invalidateAll(): void
    {
        ExpireOverdueStaleCachedUrlsAction::run();

        if (config('capell-html-cache.invalidation.mode', 'instant') === 'scheduled' || $this->workerCanRefresh()) {
            // Falls back to a full clear when no URL is tracked yet.
            MarkAllCachedUrlsStaleAction::run('frontend_output_changed');

            return;
        }

        ClearAllHtmlCacheAction::run();
    }

    private function workerCanRefresh(): bool
    {
        $connection = ScheduleOriginStaleCachedUrlRefreshAction::durableConnection();

        return $connection !== null && resolve(RefreshWorkerHeartbeat::class)->isAlive($connection);
    }
}
