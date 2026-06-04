# HTML Cache

Static HTML cache, dependency indexing, and cache administration for Capell.

## At A Glance

- Package: `capell-app/html-cache`
- Namespace: `Capell\HtmlCache\`
- Surfaces: Filament admin, Livewire, console, queue, database
- Service providers: `packages/html-cache/src/Providers/HtmlCacheServiceProvider.php`
- Capell dependencies: `capell-app/admin`, `capell-app/core`, `capell-app/frontend`
- Third-party dependencies: `laravel/framework`, `lorisleiva/laravel-actions`, `spatie/laravel-data`, `spatie/laravel-package-tools`

## Why It Helps Your Capell Workflow

- Adds static HTML cache indexing, dependency tracking, and admin cache controls for Capell public pages.
- Helps operators see stale cached URLs and refresh affected pages without manually clearing broad caches.
- Protects public-output safety by keeping cached HTML suitable for anonymous visitors, admins, crawlers, and static exports.

## Best Used With

- [Frontend Authoring](../frontend-authoring/README.md)
- [Frontend Optimizer](../frontend-optimizer/README.md)
- [Diagnostics](../diagnostics/README.md)

## What It Adds

- Static HTML cache, dependency indexing, and cache administration for Capell.
- Admin resources: `CachedModelUrlResource`.
- Admin page: `MaintenanceCachePage`.
- Dashboard widgets for cache overview, cache coverage, and stale regeneration queue.
- Livewire components: `SiteHealthCacheMap`.
- Package setup or maintenance commands.

## Code Map

| Area      | Path                                | Purpose                                                             |
| --------- | ----------------------------------- | ------------------------------------------------------------------- |
| Actions   | `packages/html-cache/src/Actions`   | Domain operations. Test these directly where possible.              |
| Data      | `packages/html-cache/src/Data`      | Structured payloads, form state, view models, and integration data. |
| Enums     | `packages/html-cache/src/Enums`     | Persisted states and Filament option values.                        |
| Models    | `packages/html-cache/src/Models`    | Eloquent records owned by the package.                              |
| Filament  | `packages/html-cache/src/Filament`  | Admin resources, pages, widgets, and settings UI.                   |
| Livewire  | `packages/html-cache/src/Livewire`  | Interactive frontend or admin components.                           |
| HTTP      | `packages/html-cache/src/Http`      | Controllers, middleware, and request handling.                      |
| Jobs      | `packages/html-cache/src/Jobs`      | Queued work and async side effects.                                 |
| Providers | `packages/html-cache/src/Providers` | Registration, extension hooks, routes, migrations, and resources.   |
| Resources | `packages/html-cache/resources`     | Views, translations, assets, and package resources.                 |
| Config    | `packages/html-cache/config`        | Package configuration and publishable config.                       |
| Database  | `packages/html-cache/database`      | Migrations, seeders, and settings migrations.                       |
| Tests     | `packages/html-cache/tests`         | Package-level Pest coverage.                                        |

## Admin Surface

- Resources: `CachedModelUrlResource`.
- Pages: `MaintenanceCachePage`, `ListCachedModelUrls`.
- Widgets: `HtmlCacheOverviewWidget`, `CacheCoverageUrlsWidget`, `HtmlCacheStaleQueueWidget`.
- Extenders: page table cache indicator, site header maintenance/cache action, Site Health cache map.

## Runtime Surface

- Livewire: `SiteHealthCacheMap`.
- Jobs: `RegisterCachedModelUrlsJob`.
- Integration: registers `StaticMaintenancePageStore` so Capell frontend maintenance pages can use the `page_cache` disk when this package is installed.
- Public cache headers are configured through `capell-html-cache.http_cache`; the filesystem cache itself has no TTL and is cleared or refreshed by invalidation.
- Access Gate active-area checks are cached briefly through `capell-html-cache.access_gate.active_area_cache_seconds` so anonymous cache decisions do not query the access gate table on every request.

## Commands

- `capell:static-site {--site=} {--internal : Render URLs through the current Laravel kernel} {--refresh : Delete affected HTML cache files before rendering}` (packages/html-cache/src/Console/Commands/StaticSiteCommand.php)

## Maintenance Pages

Capell frontend owns maintenance page rendering, the manifest, and the runtime middleware. This package only contributes the `page_cache`-backed static store plus optional admin actions for generating those files.

Generated files are written under `maintenance/` on the `page_cache` disk. The manifest at `storage/framework/capell-maintenance.json` maps host/scheme/path combinations to those files. To serve static maintenance HTML during Laravel maintenance mode, wire the frontend `frontend.maintenance` middleware into the host application's maintenance path. If this package is not installed, no static store is registered and frontend falls back to Laravel's plain 503.

## Data And Persistence

- Models: `CachedModelUrl`.
- Migrations: `2026_05_10_190854_01_create_cached_model_urls_table.php`.
- Config: `packages/html-cache/config/capell-html-cache.php`.
- Data objects live in `src/Data/`; use them for payloads, form state, and view models.

## Extension Points

- Contracts: `PageCacheNotifiable`.
- Register Capell extension points, routes, migrations, settings, render hooks, and resources from service providers.

## Install And Setup

- Install with `composer require capell-app/html-cache` in the host Capell application.
- Run migrations through the host application package install flow.
- Tune `CAPELL_HTML_CACHE_SHARED_MAX_AGE`, `CAPELL_HTML_CACHE_BROWSER_MAX_AGE`, and `CAPELL_HTML_CACHE_STALE_WHILE_REVALIDATE` when CDN/browser cache headers need to differ from the defaults.
- In this repository, verify package changes with `vendor/bin/pest`; do not use `php artisan`.

## Docs

- [docs index](docs/README.md)
- [overview.md](docs/overview.md)
- [cache-invalidation.md](docs/cache-invalidation.md)
- [screenshots.json](docs/screenshots.json)

## Testing

Run package tests from the repository root:

```bash
vendor/bin/pest packages/html-cache/tests --configuration=phpunit.xml
```

## Maintenance Notes

- Cached HTML must be safe for anonymous visitors, signed-in users, admins, crawlers, and static exports.
- Put behaviour changes in `src/Actions/`; UI classes, commands, and controllers should call actions instead of owning domain logic.
- Use package `Data` classes at boundaries instead of passing anonymous arrays between layers.
- Use backed enums for persisted values and enum labels for Filament options.
