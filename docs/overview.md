# HTML Cache

<!-- prettier-ignore-start -->

## What it does

HTML Cache serves eligible public pages from generated HTML and tracks which Capell records each cached URL used. That dependency map lets content changes clear or refresh only the affected public URLs while keeping personalised, gated, signed, or authoring output out of the shared cache.

## Setup requirements

Run the package migrations and make sure the `page_cache` filesystem is writable. Keep a queue worker running: model invalidation, dependency registration, and hit-telemetry flushes are dispatched jobs in both invalidation modes.

HTML Cache has no settings screen; its runtime, bypass, cache-age, invalidation, and optional CDN purge behaviour comes from `capell-html-cache` configuration and environment values. Run `capell:html-cache:diagnose <url>` after setup or whenever a URL will not cache; the report gives the actual eligibility reasons and stale state.

## Where it shows up

- Dashboard widgets show overall state, cached/uncached URL coverage, hit totals, and the stale-refresh queue when those widgets are enabled for the dashboard.
- **Site Health** includes a site-scoped cache map. Select a model or resource to see every cached URL that depends on it.
- The Pages table can show whether a page is cached.
- **Monitoring > Maintenance cache** generates and activates static maintenance pages separately from the normal page cache.

Viewing cached URL details requires `capell-html-cache.view`; clearing a URL requires `capell-html-cache.clear`. Maintenance controls require `capell-html-cache.maintenance.manage` or global access. Records and site maintenance actions are limited to the operator's assigned sites; only a global operator can toggle global maintenance mode.

## What can be cached

The default path caches anonymous `GET` HTML responses. It bypasses authenticated or session requests, authorization headers, signed URLs, Livewire and Inertia requests, unapproved query strings, responses marked `no-store`, and packages that declare sensitive or non-cacheable output. It also rejects HTML containing authoring markers. Eligible `404` HTML can be cached with its original status.

Cache files are public render output. Any page that varies by a custom cookie, request header, account, currency, locale selector, cart, or other visitor state must have the relevant path, cookie, or header in the configured bypass rules. Do not disable the authenticated/session bypass unless every affected response is demonstrably identical and public.

Access Gate protected requests and requests carrying its browser token bypass HTML Cache. When introducing another personalised extension, verify its cache-safety contribution and test an anonymous cache hit before launch.

## Invalidation, retries, and recovery

- **Instant** is the default. A content change queues deletion of affected cache files; until the queue job runs, the previous file can still be served.
- **Scheduled** marks affected URLs stale and deliberately keeps serving the previous good HTML while regeneration runs. In this mode the package registers `capell:html-cache:process-stale` every five minutes by default; Laravel's scheduler must be running, as well as the queue worker that marks URLs stale.
- A failed scheduled refresh keeps the previous cache file. By default it retries after five minutes, reclaims processing rows after 15 minutes, and marks a row exhausted after five attempts. A later content change resets that retry budget.
- Site-domain changes use the clear path rather than scheduled regeneration even in scheduled mode, because the old URL may no longer be valid. That clear still runs through the queue.

Use the stale-queue widget to find failed or exhausted rows, then fix the public URL, rendering error, or cache-eligibility reason. Re-save the affected content to enqueue a fresh invalidation, run `capell:html-cache:process-stale` for an immediate scheduled-mode pass, or use **Clear URL** when the old file should be removed instead of preserved.

## Maintenance mode and data visibility

Generate a site's maintenance page before enabling its site toggle. Global maintenance generates pages for accessible enabled sites, puts the application into Laravel maintenance mode, and gives the activating global operator a bypass cookie; confirm another recovery route before using it remotely.

The package stores rendered public HTML plus full URLs, paths, site/language links, dependent model types and IDs, cache timestamps, aggregate hit counts, bytes served, and last-hit times. It does not record a visitor identity for cache-hit telemetry. Treat the cache map as operational metadata and grant its view permission accordingly.

---

For how to use HTML Cache, see the [admin guide](admin-guide.md).
For developers: see the [README](../README.md).

<!-- prettier-ignore-end -->
