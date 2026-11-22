# HTML Cache reference

<!-- prettier-ignore-start -->

Developer reference for `capell-app/html-cache`. Start with the [README](../README.md) for what the extension does and how to install it.

## Package Details

HTML Cache is an **Available** Capell package in the **Capell Essentials** product group. It ships as `capell-app/html-cache` and extends these surfaces: admin, frontend.

- Status: Available
- Tier: free
- Bundle: foundation
- Composer package: `capell-app/html-cache`
- Namespace: `Capell\HtmlCache`
- Theme key: not applicable

## Technical Shape

### Service providers

- `Capell\HtmlCache\Providers\MigrationMetadataServiceProvider`
- `Capell\HtmlCache\Providers\HtmlCacheServiceProvider`

### Config files

- `packages/html-cache/config/capell-html-cache.php`

### Migrations

- `packages/html-cache/database/migrations/2026_05_10_190854_01_create_cached_model_urls_table.php`
- `packages/html-cache/database/migrations/2026_05_14_000001_create_stale_cached_urls_table.php`
- `packages/html-cache/database/migrations/2026_06_07_000001_add_telemetry_to_cached_model_urls_table.php`
- `packages/html-cache/database/migrations/2026_07_18_000001_create_html_cache_generation_runs_table.php`
- `packages/html-cache/database/migrations/2026_08_22_000001_add_scope_to_html_cache_generation_runs_table.php`
- `packages/html-cache/database/migrations/2026_10_12_000001_create_html_cache_request_observations_table.php`
- `packages/html-cache/database/migrations/2026_11_02_000001_create_html_cache_model_withdrawals_table.php`
- `packages/html-cache/database/migrations/2026_11_02_000002_create_html_cache_dependency_tracking_epochs_table.php`
- `packages/html-cache/database/migrations/2026_11_12_000001_grant_html_cache_permissions_to_default_roles.php`

### Models

- `CachedModelUrl`
- `HtmlCacheDependencyTrackingEpoch`
- `HtmlCacheGenerationRun`
- `HtmlCacheModelWithdrawal`
- `StaleCachedUrl`

### Filament classes

- `PageCachedIconColumn`
- `HasPageCacheNotification`
- `PageCachePageTableExtender`
- `MaintenanceSiteHeaderActionExtender`
- `MaintenanceCachePage`
- `UncachedPagesPage`
- `CachedModelUrlResource`
- `ListCachedModelUrls`
- `CachedModelUrlsTable`
- `HtmlCacheDashboardSettingsContributor`
- `CacheCoverageUrlsFilamentWidget`
- `HtmlCacheOverviewFilamentWidget`
- `HtmlCacheStaleQueueFilamentWidget`

### Livewire components

- `SiteHealthCacheMap`

### Policies

- `CachedModelUrlPolicy`

### Extension contracts

- `CachePurger`
- `PageCacheNotifiable`
- `ReportsEdgePurgeResponses`

### Listeners

- `RemediatePrivateSiteSpecHtmlCache`

### Actions

- `AssertHtmlCacheInvalidationTopologyAction`
- `BuildAnonymousHtmlCacheEligibilityReportAction`
- `BuildCacheMapOverviewAction`
- `BuildCachedModelUrlDiagnosticsAction`
- `BuildHtmlCacheEligibilityReportAction`
- `BuildHtmlCacheOriginDecisionAction`
- `BuildHtmlCachePublicOutputSafetyDiagnosticsAction`
- `BuildMaintenanceCacheOverviewAction`
- `BuildUncachedPagesAction`
- `CanClearHtmlCachePortCopyAction`
- `ClaimStaleCachedUrlAction`
- `ClearAllHtmlCacheAction`
- `ClearCachedPageUrlsAction`
- `ClearCachedUrlAction`
- `ClearCachedUrlsForModelAction`
- `ClearCachedUrlsForSurrogateKeysAction`
- `ClearCurrentOriginHtmlCacheAction`
- `BuildHtmlCacheDashboardStatsAction`
- `BuildHtmlCacheStaleQueueRowsAction`
- `BuildHtmlCacheUrlRowsAction`
- `DeleteCachedUrlArtefactsAction`
- `DeletePageCacheAction`
- `DisableGlobalMaintenanceAction`
- `DisableSiteMaintenanceOverrideAction`
- `DrainEdgeCachePurgeQueueAction`
- `EnableGlobalMaintenanceAction`
- `EnableSiteMaintenanceOverrideAction`
- `EnsureHtmlCachePermissionsAction`
- `ExpireOverdueStaleCachedUrlsAction`
- `ForgetCachedUrlAction`
- `ForgetCoveredEdgePurgeDropsAction`
- `GenerateStaticSiteAction`
- `GenerateStaticSitesAction`
- `GrantHtmlCacheDefaultRolePermissionsAction`
- `InspectEdgeCachePurgeReadinessAction`
- `InspectEdgeCachePurgeThrottleAction`
- `ListCacheMapResourceOptionsAction`
- `MarkAllCachedUrlsStaleAction`
- `MarkCachedUrlStaleAction`
- `MarkCachedUrlsForModelStaleAction`
- `MarkCachedUrlsForSiteStaleAction`
- `NotifyClearCachedPagesAction`
- `PlanEditorHtmlCacheConsequencesAction`
- `PrepareMaintenanceCacheAction`
- `PrepareSiteMaintenanceCacheAction`
- `ProcessStaleHtmlCacheAction`
- `PruneHtmlCacheLockFilesAction`
- `PruneHtmlCacheMetadataAction`
- `PurgeEdgeCacheAction`
- `QueueEdgeCachePurgeAction`
- `QueueMaintenancePageGenerationAction`
- `ReadHtmlCacheRequestMetricsAction`
- `RecordCachedModelUrlsAction`
- `RecordHtmlCacheHitAction`
- `RecordHtmlCacheRequestObservationAction`
- `RefreshCachedUrlAtomicallyAction`
- `RefreshOriginStaleCachedUrlAction`
- `RenderCoalescedHtmlCacheMissAction`
- `RenderWithinHtmlCacheBudgetAction`
- `ResolveCachedUrlsForModelAction`
- `ResolveCachedUrlsForSurrogateKeysAction`
- `ResolveEdgeCacheTagsAction`
- `ResolveHtmlCachePageUrlAction`
- `RetireCachedUrlAction`
- `ScheduleOriginStaleCachedUrlRefreshAction`
- `SelectStaleHtmlCacheCandidatesAction`
- `WithdrawCachedHtmlForOpenedAccessAreasAction`
- `WithdrawCachedUrlsAction`
- `WithdrawCachedUrlsForModelAction`
- `WriteRefreshedHtmlCacheFileAction`

### Data objects

- `CacheMapModelSummaryData`
- `CacheMapOverviewData`
- `CacheMapResourceSummaryData`
- `HtmlCacheDashboardStatsData`
- `EdgeCachePurgeData`
- `EdgeCachePurgeReadinessData`
- `EdgePurgeBatchData`
- `EdgePurgeDrainResultData`
- `EdgePurgeDroppedData`
- `EdgePurgeEnqueueResultData`
- `EdgePurgePendingStatusData`
- `EdgePurgeResponseData`
- `EdgePurgeThrottleSettingsData`
- `EdgePurgeThrottleStatusData`
- `EdgePurgeZoneRateData`
- `HtmlCacheClearResult`
- `HtmlCacheEligibilityReportData`
- `HtmlCacheHitBatchData`
- `HtmlCacheOriginDecisionData`
- `HtmlCacheRequestMetricsData`
- `HtmlCacheWithdrawalData`
- `MaintenanceCacheDomainData`
- `MaintenanceCacheOverviewData`
- `MaintenancePreparationFailureData`
- `MaintenanceSiteStatusData`
- `StaleHtmlCacheProcessResultData`
- `UncachedPageData`
- `UncachedPagesData`

### Jobs

- `ClearAuthorisedPageCacheJob`
- `FlushHtmlCacheHitBatchJob`
- `GenerateMaintenancePagesJob`
- `RefreshOriginStaleCachedUrlJob`
- `RegisterCachedModelUrlsJob`

### Command signatures

- `capell:html-cache:clear`
- `capell:html-cache:diagnose`
- `capell:html-cache:edge-purge:verify`
- `capell:html-cache:process-stale`
- `capell:static-site`

### Scheduled commands

- `capell:html-cache:process-stale (package registered)`

### Console command classes

- `ClearHtmlCacheCommand`
- `DiagnoseHtmlCacheCommand`
- `ProcessStaleHtmlCacheCommand`
- `StaticSiteCommand`
- `VerifyEdgeCachePurgeCommand`

### Manifest contributions

- `admin-page: Capell\HtmlCache\Manifest\HtmlCacheAdminPagesContribution`
- `admin-resource: Capell\HtmlCache\Manifest\HtmlCacheAdminResourcesContribution`
- `console-command: Capell\HtmlCache\Manifest\HtmlCacheConsoleCommandsContribution`
- `dashboard-widget: Capell\HtmlCache\Manifest\HtmlCacheDashboardFilamentWidgetsContribution`
- `model: Capell\HtmlCache\Manifest\HtmlCacheModelsContribution`
- `route: Capell\HtmlCache\Manifest\HtmlCacheFrontendRoutesContribution`
- `scheduled-job: Capell\HtmlCache\Manifest\HtmlCacheStaleProcessingScheduleContribution`

### Health checks

- `Capell\HtmlCache\Health\HtmlCacheHealthCheck`

### Blade views

- `packages/html-cache/resources/views/filament/pages/maintenance-cache.blade.php`
- `packages/html-cache/resources/views/filament/pages/partials/uncached-pages-table.blade.php`
- `packages/html-cache/resources/views/filament/pages/uncached-pages.blade.php`
- `packages/html-cache/resources/views/livewire/site-health-cache-map.blade.php`

### Cache tags

- `html-cache`


## Data Model

- Required tables: `cached_model_urls`, `html_cache_dependency_tracking_epochs`, `html_cache_generation_runs`, `html_cache_model_withdrawals`, `html_cache_request_observations`, `stale_cached_urls`.
- Models: `CachedModelUrl`, `HtmlCacheDependencyTrackingEpoch`, `HtmlCacheGenerationRun`, `HtmlCacheModelWithdrawal`, `StaleCachedUrl`.
- Core record references in migrations: `sites via site_id`, `languages via language_id`, `site domains via site_domain_id`.
- Migration files: `2026_05_10_190854_01_create_cached_model_urls_table.php`, `2026_05_14_000001_create_stale_cached_urls_table.php`, `2026_06_07_000001_add_telemetry_to_cached_model_urls_table.php`, `2026_07_18_000001_create_html_cache_generation_runs_table.php`, `2026_08_22_000001_add_scope_to_html_cache_generation_runs_table.php`, `2026_10_12_000001_create_html_cache_request_observations_table.php`, `2026_11_02_000001_create_html_cache_model_withdrawals_table.php`, `2026_11_02_000002_create_html_cache_dependency_tracking_epochs_table.php`, `2026_11_12_000001_grant_html_cache_permissions_to_default_roles.php`.
- Migration impact: run host migrations through the package install flow before opening package surfaces.
- Deletion/retention behaviour: migrations declare null-on-delete relationships; no timed pruning or retention schedule is declared in `capell.json`.

<!-- prettier-ignore-end -->
