# HTML Cache

<!-- prettier-ignore-start -->

## What This Plugin Adds

HTML Cache is an **Available**, **Schema-owning** Capell package in the **Capell Foundation** product group. It ships as `capell-app/html-cache` and extends these surfaces: admin, frontend.

Full-page static HTML cache for Capell with dependency-indexed invalidation, scheduled stale-regeneration, and public-output safety guarantees.

After install, operators get cache maintenance pages, diagnostics, dashboard widgets, stale-queue tooling, and public middleware that only stores anonymous-safe HTML.

Status details:

- Status: Available
- Tier: free
- Bundle: foundation
- Composer package: `capell-app/html-cache`
- Namespace: `Capell\HtmlCache`
- Theme key: not applicable

## Why It Matters

**For developers:** The package gives developers package-owned service providers, Actions, Data objects, models, Filament classes, and Blade views instead of pushing this behaviour into core or application code.

**For teams:** Serve Capell pages as static HTML for sub-millisecond responses - with automatic, dependency-aware invalidation that keeps cached pages fresh and never leaks gated or authoring content to anonymous visitors.

## Screens And Workflow

Screenshot contract: `screenshots.json`.

- HTML Cache maintenance cache page (admin, required).
- Cached model URLs resource index (admin, required).
- HTML Cache dashboard widgets (admin, required).
- HTML Cache site health cache map (admin, required).
- Page table cache indicator (admin, required).
- Anonymous public cache hit (frontend, required).
- Static maintenance page output (frontend, required).

## Technical Shape

- Service providers: `Capell\HtmlCache\Providers\HtmlCacheServiceProvider`.
- Config files: `packages/html-cache/config/capell-html-cache.php`.
- Migrations: `packages/html-cache/database/migrations/2026_05_10_190854_01_create_cached_model_urls_table.php`, `packages/html-cache/database/migrations/2026_05_14_000001_create_stale_cached_urls_table.php`, `packages/html-cache/database/migrations/2026_06_07_000001_add_telemetry_to_cached_model_urls_table.php`.
- Models: `CachedModelUrl`, `StaleCachedUrl`.
- Filament classes: `PageCachedIconColumn`, `HasPageCacheNotification`, `PageCachePageTableExtender`, `MaintenanceSiteHeaderActionExtender`, `MaintenanceCachePage`, `CachedModelUrlResource`, `ListCachedModelUrls`, `CachedModelUrlsTable`, `HtmlCacheDashboardSettingsContributor`, `CacheCoverageUrlsWidget`, `HtmlCacheOverviewWidget`, `HtmlCacheStaleQueueWidget`.
- Livewire components: `SiteHealthCacheMap`.
- Actions: `BuildCacheMapOverviewAction`, `BuildCachedModelUrlDiagnosticsAction`, `BuildHtmlCacheEligibilityReportAction`, `BuildHtmlCachePublicOutputSafetyDiagnosticsAction`, `ClearAllHtmlCacheAction`, `ClearCachedPageUrlsAction`, `ClearCachedUrlAction`, `ClearCachedUrlsForModelAction`, `ClearCachedUrlsForSurrogateKeysAction`, `BuildHtmlCacheDashboardStatsAction`, `BuildHtmlCacheStaleQueueRowsAction`, `BuildHtmlCacheUrlRowsAction`, `and 14 more`.
- Data objects: `CacheMapModelSummaryData`, `CacheMapOverviewData`, `CacheMapResourceSummaryData`, `HtmlCacheDashboardStatsData`, `HtmlCacheClearResult`, `HtmlCacheEligibilityReportData`.
- Jobs: `RegisterCachedModelUrlsJob`.
- Console command classes: `ClearHtmlCacheCommand`, `DiagnoseHtmlCacheCommand`, `ProcessStaleHtmlCacheCommand`, `StaticSiteCommand`.
- Health checks: `Capell\HtmlCache\Health\HtmlCacheHealthCheck`.
- Blade views: `packages/html-cache/resources/views/filament/pages/maintenance-cache.blade.php`, `packages/html-cache/resources/views/livewire/site-health-cache-map.blade.php`.
- Cache tags: `html-cache`.

## Data Model

- Models: `CachedModelUrl`, `StaleCachedUrl`.
- Migration files: `2026_05_10_190854_01_create_cached_model_urls_table.php`, `2026_05_14_000001_create_stale_cached_urls_table.php`, `2026_06_07_000001_add_telemetry_to_cached_model_urls_table.php`.
- Migration impact: run host migrations through the package install flow before opening package surfaces.
- Deletion/retention behaviour: `instant` invalidation deletes cache files and index rows immediately; `scheduled` invalidation keeps stale rows until `capell:html-cache:process-stale` refreshes, fails, or exhausts them for diagnostics.

## Install Impact

- Admin navigation: adds package-owned Filament classes when registered.
- Permissions: `capell-html-cache.view`, `capell-html-cache.clear`.
- Public routes: none detected in package route files.
- Database changes: package migrations are declared.
- Settings: no package settings declared.
- Queues or schedules: scheduled invalidation uses `capell:html-cache:process-stale`; dependency recording can run deferred, sync, or async based on `capell-html-cache.model_event_registration_mode`.
- Cache tags: `html-cache`.
- Commands: console command classes detected: `ClearHtmlCacheCommand`, `DiagnoseHtmlCacheCommand`, `ProcessStaleHtmlCacheCommand`, `StaticSiteCommand`.

## Common Pitfalls

- Run migrations before opening package resources or public routes.
- Keep public Blade and cached HTML free of authoring markers, model IDs, permissions, signed editor URLs, and lazy database queries.
- Keep `composer.json`, `composer.local.json`, `capell.json`, docs, screenshots, and tests aligned when the package surface changes.

## Troubleshooting

| Symptom | Likely cause | Check | Fix |
| --- | --- | --- | --- |
| Package surface is missing after install | Provider or manifest is not loaded | Confirm `capell.json`, package `composer.json`, and provider registration | Reinstall the package, refresh Composer autoload, and clear host caches |
| Admin screen or command fails on missing table | Package migrations have not run | Check the tables listed in `Data Model` | Run host migrations and rerun the focused package test |
| Background work does not run | Queue worker or scheduled command is not active | Check package jobs, commands, and host scheduler configuration | Start the queue or scheduler, then run the focused command or package test |
| Public output leaks unexpected state | Render data, cache variation, or authoring boundary has regressed | Check public Blade, cache tags, and public-output safety tests | Move data loading out of Blade and rerun the package public-output tests |

## Quick Start

1. Install the package in a host Capell app: `composer require capell-app/html-cache`.
2. Run host migrations so `cached_model_urls` and `stale_cached_urls` exist.
3. Open the maintenance cache page or dashboard widgets and verify HTML Cache appears.

## Next Steps

- [Package docs index](README.md)
- [Screenshot contract](screenshots.json)
- [Marketplace assets](assets/marketplace/)
- [Capell content language plan](../../../docs/CONTENT_LANGUAGE_PLAN.md)
- [Capell documentation design system](../../../docs/DESIGN_SYSTEM.md)
- [Capell and package ERD notes](../../../docs/erd/capell-and-package-erds.md)
- Related packages: [Site Discovery](../../site-discovery/README.md).
- Focused tests: `vendor/bin/pest packages/html-cache/tests --configuration=phpunit.xml`.

<!-- prettier-ignore-end -->
