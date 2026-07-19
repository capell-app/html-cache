<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Jobs;

use Capell\Core\Models\Site;
use Capell\Frontend\Actions\GenerateMaintenancePageCacheAction;
use Capell\Frontend\Support\Maintenance\MaintenanceManifestStore;
use Capell\HtmlCache\Actions\PurgeEdgeCacheAction;
use Capell\HtmlCache\Data\EdgeCachePurgeData;
use Capell\HtmlCache\Models\HtmlCacheGenerationRun;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Throwable;

final class GenerateMaintenancePagesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /** @param list<int> $siteIds */
    public function __construct(
        public readonly string $runId,
        public readonly array $siteIds,
        public readonly bool $enableGlobal = false,
        public readonly ?string $secret = null,
        public readonly ?int $activateSiteId = null,
    ) {}

    public function uniqueId(): string
    {
        return $this->runId;
    }

    public function handle(): void
    {
        $run = HtmlCacheGenerationRun::query()->find($this->runId);

        if (! $run instanceof HtmlCacheGenerationRun || $run->status !== HtmlCacheGenerationRun::STATUS_PENDING) {
            return;
        }

        $run->update([
            'status' => HtmlCacheGenerationRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        foreach ($this->siteIds as $siteId) {
            $this->generateSite($run, $siteId);
        }

        $run->refresh();
        $failed = $run->failed_sites > 0;

        $run->update([
            'status' => $failed ? HtmlCacheGenerationRun::STATUS_FAILED : HtmlCacheGenerationRun::STATUS_COMPLETED,
            'finished_at' => now(),
        ]);

        if ($failed) {
            return;
        }

        if ($this->activateSiteId !== null) {
            resolve(MaintenanceManifestStore::class)->setSiteActive($this->activateSiteId, true);
            PurgeEdgeCacheAction::dispatch(new EdgeCachePurgeData(tags: ['site-' . $this->activateSiteId]));
        }

        if ($this->enableGlobal && is_string($this->secret) && $this->secret !== '') {
            resolve(MaintenanceManifestStore::class)->setGlobalActive(true);
            PurgeEdgeCacheAction::dispatch(new EdgeCachePurgeData(purgeAll: true));
            Artisan::call('down', ['--secret' => $this->secret]);
        }
    }

    public function failed(?Throwable $throwable): void
    {
        HtmlCacheGenerationRun::query()
            ->whereKey($this->runId)
            ->whereNull('finished_at')
            ->update([
                'status' => HtmlCacheGenerationRun::STATUS_FAILED,
                'errors' => ['job' => $throwable instanceof Throwable ? Str::limit($throwable->getMessage(), 1000, '') : 'Maintenance generation job failed.'],
                'finished_at' => now(),
            ]);
    }

    private function generateSite(HtmlCacheGenerationRun $run, int $siteId): void
    {
        $site = Site::query()->find($siteId);

        if (! $site instanceof Site) {
            $this->recordSiteResult($run, $siteId, 'Site no longer exists.');

            return;
        }

        try {
            GenerateMaintenancePageCacheAction::run($site);
            $this->recordSiteResult($run, $siteId);
        } catch (Throwable $throwable) {
            $this->recordSiteResult($run, $siteId, Str::limit($throwable->getMessage(), 1000, ''));
        }
    }

    private function recordSiteResult(HtmlCacheGenerationRun $run, int $siteId, ?string $error = null): void
    {
        $run->refresh();

        if ($error === null) {
            $run->increment('completed_sites');

            return;
        }

        $errors = is_array($run->errors) ? $run->errors : [];
        $errors[(string) $siteId] = $error;
        $run->update(['errors' => $errors]);
        $run->increment('failed_sites');
    }
}
