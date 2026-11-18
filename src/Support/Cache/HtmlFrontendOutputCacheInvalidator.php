<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Cache;

use Capell\Core\Support\TableName;
use Capell\Frontend\Contracts\FrontendOutputCacheInvalidator;
use Capell\HtmlCache\Actions\ClearAllHtmlCacheAction;
use Capell\HtmlCache\Actions\ExpireOverdueStaleCachedUrlsAction;
use Capell\HtmlCache\Actions\MarkAllCachedUrlsStaleAction;
use Capell\HtmlCache\Actions\ScheduleOriginStaleCachedUrlRefreshAction;
use Illuminate\Support\Facades\Schema;
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
    private bool $migrated = false;

    #[Override]
    public function invalidateAll(): void
    {
        // Before html-cache has migrated nothing is tracked or cached, so there is nothing to
        // invalidate. Callers such as other packages' install migrations can run in that state.
        if (! $this->migrated()) {
            return;
        }

        ExpireOverdueStaleCachedUrlsAction::run();

        if (config('capell-html-cache.invalidation.mode', 'instant') === 'scheduled' || $this->workerCanRefresh()) {
            // Falls back to a full clear when no URL is tracked yet.
            MarkAllCachedUrlsStaleAction::run('frontend_output_changed');

            return;
        }

        ClearAllHtmlCacheAction::run();
    }

    /** A table that exists stays; one that does not is checked again next time, as the migration may run later. */
    private function migrated(): bool
    {
        if ($this->migrated) {
            return true;
        }

        return $this->migrated = Schema::hasTable(TableName::resolve('stale_cached_urls'))
            && Schema::hasTable(TableName::resolve('cached_model_urls'));
    }

    private function workerCanRefresh(): bool
    {
        $connection = ScheduleOriginStaleCachedUrlRefreshAction::durableConnection();

        return $connection !== null && resolve(RefreshWorkerHeartbeat::class)->isAlive($connection);
    }
}
