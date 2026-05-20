# HTML Cache Overview

HTML Cache adds static public HTML caching, cache dependency indexing, stale-regeneration queues, and admin tools for maintenance-page cache generation.

## What It Adds

- Frontend middleware aliases for cache serving, model-event tracking, and session-cookie prevention on cacheable requests.
- `CachedModelUrlResource` for inspecting cached URL dependencies.
- `MaintenanceCachePage` for generating and toggling static maintenance pages.
- Dashboard widgets for cache overview, cache coverage URLs, and regeneration queue status.
- Site health cache-map Livewire component and public cached HTML safety diagnostics.
- Commands for clearing HTML cache, processing stale cache records, and generating static sites.

## Install Impact

- Requires `capell-app/admin`, `capell-app/core`, and `capell-app/frontend`.
- Adds `cached_model_urls` and stale cached URL tables.
- Registers permissions `capell-html-cache.view` and `capell-html-cache.clear`.
- Adds frontend cache middleware to the frontend route middleware registry.
- Registers a `page_cache` filesystem disk when missing.

## Admin Surfaces

| Surface                     | URL/route expectation           | Notes                                                                               |
| --------------------------- | ------------------------------- | ----------------------------------------------------------------------------------- |
| Maintenance cache page      | `/admin/maintenance-cache`      | Generates maintenance page cache and toggles global/per-site maintenance state.     |
| Cached model URLs resource  | Admin resource under Monitoring | Lists cached URLs, model dependencies, site/language scope, and clear/open actions. |
| Dashboard widgets           | Main admin dashboard            | Shows cache overview, coverage URLs, and stale queue status.                        |
| Page table extender         | Core Pages resource             | Adds cache state/action context to page tables.                                     |
| Site header action extender | Core Sites resource             | Adds maintenance/cache actions to site-level admin context.                         |
| Site health cache map       | Site Health page                | Shows cache-map diagnostics and public output safety checks.                        |

## Frontend Surfaces

| Surface                     | Use case                                                                                  | Screenshot                    |
| --------------------------- | ----------------------------------------------------------------------------------------- | ----------------------------- |
| Cached public page response | Verify cacheable anonymous output is served without session cookies or authoring markers. | `html-cache-public-cache-hit` |
| Static maintenance page     | Verify generated maintenance HTML for a site/domain.                                      | `html-cache-maintenance-page` |

## Demo Setup

Install the core baseline and `capell-app/html-cache`, run migrations, warm at least one public page, then capture both admin monitoring screens and anonymous public output. For maintenance screenshots, generate a site maintenance page from the admin page before capture.

## Screenshot Coverage

The screenshot contract should cover the maintenance page, cached URL index, dashboard widgets, page/site admin extensions, site health cache-map output, a public cache hit, and a static maintenance page.

## Public Safety Checks

Anonymous cached HTML must not expose authoring HTML, editor JavaScript, editable markers, model IDs, field paths, permissions, package names, selectors, or signed editor URLs. Keep this check in every final screenshot run for this package.

## Verification

Run package tests from the repository root:

```bash
vendor/bin/pest packages/html-cache/tests --configuration=phpunit.xml
```
