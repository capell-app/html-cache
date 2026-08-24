<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Core\Models\Site;
use Capell\Frontend\Support\Maintenance\MaintenanceManifestStore;
use Capell\HtmlCache\Data\MaintenanceCacheDomainData;
use Capell\HtmlCache\Data\MaintenanceCacheOverviewData;
use Capell\HtmlCache\Data\MaintenanceSiteStatusData;
use Capell\HtmlCache\Enums\MaintenanceCacheAttentionReason;
use Capell\HtmlCache\Enums\MaintenanceCacheStatus;
use Capell\HtmlCache\Enums\MaintenanceSiteStatus;
use Capell\HtmlCache\Models\HtmlCacheGenerationRun;
use Illuminate\Support\Collection;
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

        $activeRun = HtmlCacheGenerationRun::query()
            ->whereIn('status', [HtmlCacheGenerationRun::STATUS_PENDING, HtmlCacheGenerationRun::STATUS_RUNNING])
            ->latest('created_at')
            ->first();

        $latestFinishedRun = HtmlCacheGenerationRun::query()
            ->whereIn('status', [HtmlCacheGenerationRun::STATUS_COMPLETED, HtmlCacheGenerationRun::STATUS_FAILED])
            ->latest('finished_at')
            ->first();

        $enabledSites = $accessibleSites->filter(static fn (Site $site): bool => $site->isEnabled())->values();

        $siteRows = $accessibleSites
            ->map(fn (Site $site): MaintenanceSiteStatusData => $this->buildSiteRow($site, $manifest, $activeRun, $globalManifestActive))
            ->values()
            ->all();

        $readySites = $enabledSites->filter(
            fn (Site $site): bool => data_get($manifest, 'sites.' . $site->id . '.domains', []) !== [],
        )->count();

        $attentionReasons = $this->buildAttentionReasons(
            $enabledSites,
            $latestFinishedRun,
            $activeRun,
            $globalManifestActive,
            $artisanMaintenanceActive,
        );

        $status = $this->resolveStatus($attentionReasons, $globalManifestActive, $activeRun, $enabledSites, $readySites);

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
            currentRunTotalSites: $activeRun instanceof HtmlCacheGenerationRun ? $activeRun->total_sites : 0,
            currentRunCompletedSites: $activeRun instanceof HtmlCacheGenerationRun ? $activeRun->completed_sites : 0,
            currentRunFailedSites: $activeRun instanceof HtmlCacheGenerationRun ? $activeRun->failed_sites : 0,
        );
    }

    /**
     * @param  Collection<int, Site>  $enabledSites
     * @return list<MaintenanceCacheAttentionReason>
     */
    private function buildAttentionReasons(
        Collection $enabledSites,
        ?HtmlCacheGenerationRun $latestFinishedRun,
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

        if (
            ! $activeRun instanceof HtmlCacheGenerationRun
            && $latestFinishedRun instanceof HtmlCacheGenerationRun
            && $latestFinishedRun->status === HtmlCacheGenerationRun::STATUS_FAILED
        ) {
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
