<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Core\Models\Site;
use Capell\Frontend\Support\Maintenance\MaintenanceManifestStore;
use Capell\HtmlCache\Data\MaintenanceCacheDomainData;
use Capell\HtmlCache\Data\MaintenanceCacheOverviewData;
use Capell\HtmlCache\Data\MaintenancePreparationFailureData;
use Capell\HtmlCache\Data\MaintenanceSiteStatusData;
use Capell\HtmlCache\Enums\MaintenanceCacheAttentionReason;
use Capell\HtmlCache\Enums\MaintenanceCacheStatus;
use Capell\HtmlCache\Enums\MaintenanceSiteStatus;
use Capell\HtmlCache\Models\HtmlCacheGenerationRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Projects the maintenance manifest, accessible sites, and the current
 * generation run into the five explicit states the Maintenance cache page
 * presents (Off, Preparing, Ready, Active, Attention). This is the single
 * source of truth for what the page and its header/site actions may show;
 * nothing in the Filament page recomputes this logic inline.
 */
final class BuildMaintenanceCacheOverviewAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly MaintenanceManifestStore $manifestStore,
    ) {}

    /**
     * @param  Collection<int, Site>  $accessibleSites  every site the current actor may see, with
     *                                                  `siteDomains` eager-loaded. Rows are rendered for all of them; only
     *                                                  enabled sites count toward global readiness.
     */
    public function handle(Collection $accessibleSites): MaintenanceCacheOverviewData
    {
        $manifest = $this->manifestStore->read();
        $globalManifestActive = ($manifest['global_active'] ?? false) === true;
        $artisanMaintenanceActive = app()->maintenanceMode()->active();

        $siteIds = array_values($accessibleSites->map(static fn (Site $site): int => $site->id)->all());

        $activeRun = $this->accessibleRuns($siteIds)
            ->whereIn('status', [HtmlCacheGenerationRun::STATUS_PENDING, HtmlCacheGenerationRun::STATUS_RUNNING])
            ->latest('created_at')
            ->latest('id')
            ->first();

        $enabledSites = $accessibleSites->filter(static fn (Site $site): bool => $site->isEnabled())->values();

        $siteRows = $accessibleSites
            ->map(fn (Site $site): MaintenanceSiteStatusData => $this->buildSiteRow($site, $manifest, $activeRun, $globalManifestActive))
            ->values()
            ->all();

        $readySites = $enabledSites->filter(
            fn (Site $site): bool => data_get($manifest, 'sites.' . $site->id . '.domains', []) !== [],
        )->count();

        $failureHistory = $activeRun === null ? $this->preparationFailures($accessibleSites) : ['failures' => [], 'blocksReadiness' => false];

        $attentionReasons = $this->buildAttentionReasons(
            $enabledSites,
            $failureHistory['blocksReadiness'],
            $activeRun,
            $globalManifestActive,
            $artisanMaintenanceActive,
        );

        $status = $this->resolveStatus($attentionReasons, $globalManifestActive, $activeRun, $enabledSites, $readySites);

        // Existing runs have aggregate counters only. Do not reveal counts for inaccessible sites.
        $showRunCounters = $activeRun === null || array_diff($activeRun->site_ids ?? [], $siteIds) === [];

        return new MaintenanceCacheOverviewData(
            status: $status,
            globalManifestActive: $globalManifestActive,
            artisanMaintenanceActive: $artisanMaintenanceActive,
            totalSites: $enabledSites->count(),
            readySites: $readySites,
            sites: array_values($siteRows),
            attentionReasons: $attentionReasons,
            currentRunId: $activeRun instanceof HtmlCacheGenerationRun ? $activeRun->id : null,
            currentRunStatus: $activeRun instanceof HtmlCacheGenerationRun ? $activeRun->status : null,
            currentRunTotalSites: $showRunCounters && $activeRun instanceof HtmlCacheGenerationRun ? $activeRun->total_sites : 0,
            currentRunCompletedSites: $showRunCounters && $activeRun instanceof HtmlCacheGenerationRun ? $activeRun->completed_sites : 0,
            currentRunFailedSites: $showRunCounters && $activeRun instanceof HtmlCacheGenerationRun ? $activeRun->failed_sites : 0,
            failures: $failureHistory['failures'],
            showRunCounters: $showRunCounters,
        );
    }

    /**
     * @param  Collection<int, Site>  $accessibleSites
     * @return array{failures: list<MaintenancePreparationFailureData>, blocksReadiness: bool}
     */
    private function preparationFailures(Collection $accessibleSites): array
    {
        $failures = [];
        $blocksReadiness = false;
        $seenJobs = [];
        foreach ($accessibleSites as $site) {
            // A later success for a different site does not resolve this site's failure.
            $run = $this->accessibleRuns([$site->id])
                ->whereIn('status', [HtmlCacheGenerationRun::STATUS_COMPLETED, HtmlCacheGenerationRun::STATUS_FAILED])
                ->latest('finished_at')->latest('created_at')->latest('id')->first();
            if ($run?->status !== HtmlCacheGenerationRun::STATUS_FAILED) {
                continue;
            }

            $errors = $run->errors ?? [];
            // Disabled sites retain their history but are outside global preparation.
            // Evaluate before deduplicating job failures shared with another site.
            $siteFailed = array_key_exists((string) $site->id, $errors) || array_key_exists('job', $errors) || $errors === [];
            $blocksReadiness = $blocksReadiness || ($site->isEnabled() && $siteFailed);
            if (array_key_exists((string) $site->id, $errors)) {
                $failures[] = new MaintenancePreparationFailureData(
                    summary: __('capell-html-cache::admin.preparation_site_failed', ['site' => $site->name]),
                    recovery: $this->failureRecovery($errors[(string) $site->id]),
                );
            }

            if ((array_key_exists('job', $errors) || $errors === []) && ! isset($seenJobs[$run->id])) {
                $seenJobs[$run->id] = true;
                $failures[] = new MaintenancePreparationFailureData(
                    summary: $errors === [] ? __('capell-html-cache::admin.preparation_failure_unrecorded') : __('capell-html-cache::admin.preparation_worker_failed'),
                    recovery: __('capell-html-cache::admin.preparation_worker_recovery'),
                );
            }
        }

        return ['failures' => $failures, 'blocksReadiness' => $blocksReadiness];
    }

    private function failureRecovery(?string $error): string
    {
        // Only project known categories. Stored exceptions are never safe display text.
        $message = strtolower($error ?? '');
        $key = match (true) {
            str_contains($message, 'store'), str_contains($message, 'writ'), str_contains($message, 'permission denied'), str_contains($message, 'disk') => 'preparation_storage_recovery',
            str_contains($message, 'render'), str_contains($message, 'view'), str_contains($message, 'theme') => 'preparation_template_recovery',
            default => 'preparation_site_recovery',
        };

        return Lang::string('capell-html-cache::admin.' . $key);
    }

    /** @param list<int> $siteIds
     * @return Builder<HtmlCacheGenerationRun>
     */
    private function accessibleRuns(array $siteIds): Builder
    {
        return HtmlCacheGenerationRun::query()->where(static function (Builder $query) use ($siteIds): void {
            if ($siteIds === []) {
                $query->whereRaw('1 = 0');

                return;
            }

            foreach ($siteIds as $siteId) {
                $query->orWhereJsonContains('site_ids', $siteId);
            }
        });
    }

    /**
     * @param  Collection<int, Site>  $enabledSites
     * @return list<MaintenanceCacheAttentionReason>
     */
    private function buildAttentionReasons(
        Collection $enabledSites,
        bool $generationFailed,
        ?HtmlCacheGenerationRun $activeRun,
        bool $globalManifestActive,
        bool $artisanMaintenanceActive,
    ): array {
        $reasons = [];

        if ($enabledSites->isEmpty()) {
            $reasons[] = MaintenanceCacheAttentionReason::NoAccessibleSites;
        } elseif ($enabledSites->every(static fn (Site $site): bool => $site->siteDomains->isEmpty())) {
            $reasons[] = MaintenanceCacheAttentionReason::NoSiteDomainsConfigured;
        }

        if (! $activeRun instanceof HtmlCacheGenerationRun && $generationFailed) {
            $reasons[] = MaintenanceCacheAttentionReason::GenerationFailed;
        }

        if ($globalManifestActive !== $artisanMaintenanceActive) {
            $reasons[] = MaintenanceCacheAttentionReason::ArtisanStateDrift;
        }

        return $reasons;
    }

    /**
     * @param  list<MaintenanceCacheAttentionReason>  $attentionReasons
     * @param  Collection<int, Site>  $enabledSites
     */
    private function resolveStatus(
        array $attentionReasons,
        bool $globalManifestActive,
        ?HtmlCacheGenerationRun $activeRun,
        Collection $enabledSites,
        int $readySites,
    ): MaintenanceCacheStatus {
        return match (true) {
            in_array(MaintenanceCacheAttentionReason::ArtisanStateDrift, $attentionReasons, true) => MaintenanceCacheStatus::Attention,
            $globalManifestActive => MaintenanceCacheStatus::Active,
            $activeRun instanceof HtmlCacheGenerationRun => MaintenanceCacheStatus::Preparing,
            $attentionReasons !== [] => MaintenanceCacheStatus::Attention,
            $enabledSites->isNotEmpty() && $readySites === $enabledSites->count() => MaintenanceCacheStatus::Ready,
            default => MaintenanceCacheStatus::Off,
        };
    }

    /** @param array<string, mixed> $manifest */
    private function buildSiteRow(
        Site $site,
        array $manifest,
        ?HtmlCacheGenerationRun $activeRun,
        bool $globalManifestActive,
    ): MaintenanceSiteStatusData {
        $siteId = $site->id;
        $domainsRaw = data_get($manifest, 'sites.' . $siteId . '.domains', []);
        $siteOverrideActive = data_get($manifest, 'sites.' . $siteId . '.active') === true;
        $isPreparing = $activeRun instanceof HtmlCacheGenerationRun && $activeRun->targetsSite($siteId);

        $status = match (true) {
            $globalManifestActive => MaintenanceSiteStatus::CoveredByGlobal,
            $isPreparing => MaintenanceSiteStatus::Preparing,
            $siteOverrideActive => MaintenanceSiteStatus::Active,
            $domainsRaw !== [] => MaintenanceSiteStatus::Generated,
            default => MaintenanceSiteStatus::Missing,
        };

        $domains = collect(is_array($domainsRaw) ? $domainsRaw : [])
            ->filter(static fn (mixed $domain): bool => is_array($domain))
            ->map(static fn (array $domain): MaintenanceCacheDomainData => new MaintenanceCacheDomainData(
                scheme: (string) ($domain['scheme'] ?? ''),
                domain: (string) ($domain['domain'] ?? ''),
                path: (string) ($domain['path'] ?? '/'),
                file: (string) ($domain['file'] ?? ''),
                generatedAt: is_string($domain['generated_at'] ?? null) ? $domain['generated_at'] : null,
            ))
            ->values();

        $lastGeneratedAt = $domains
            ->map(static fn (MaintenanceCacheDomainData $domain): ?string => $domain->generatedAt)
            ->filter()
            ->sort()
            ->last();

        return new MaintenanceSiteStatusData(
            siteId: $siteId,
            siteName: (string) $site->name,
            enabled: $site->isEnabled(),
            status: $status,
            domains: array_values($domains->all()),
            lastGeneratedAt: is_string($lastGeneratedAt) ? $lastGeneratedAt : null,
        );
    }
}
