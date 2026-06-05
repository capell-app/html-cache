# HTML Cache — Improvement & Growth Plan

> Package: capell-app/html-cache · Kind: package · Tier: free · Product group: Capell Foundation · Bundle: foundation · Status: Draft

## 1. Snapshot

Full-page HTML caching for Capell public pages. Caching is **filesystem-based**: a `frontend.cache` middleware (`src/Http/Middleware/HtmlCacheMiddleware.php`) writes/serves static `.html` (and `.404.html`) files on the `page_cache` disk, keyed by `{scheme}.{host}/{url-segments}/{filename}` (`PageCache::getFileFromRequest`). Two persistence tables back it: `cached_model_urls` (model→URL dependency index) and `stale_cached_urls` (scheduled-invalidation queue). Invalidation has two modes — `instant` (delete files on model events) and `scheduled` (mark rows stale, then `capell:html-cache:process-stale` re-renders through the kernel and atomically replaces files). Variance is by host+path only; query-string requests are never cached (`PageCache::shouldCachePage` bails on `query->count() > 0`), authenticated/session/`Authorization` requests bypass, access-gate areas bypass, and any extension declaring `cacheable:false`/`sensitiveOutput` blocks caching site-wide via `ExtensionCacheSafetyResolver`. Surfaces: `admin`, `frontend`. Requires `admin`, `core`, `frontend`; supports `site-discovery`. Dependents/integrators: access-gate, frontend-optimizer, frontend-authoring, layout-builder, publishing-studio all touch its invalidation actions. Shipped marketplace summary: **"Serve Capell pages as static HTML for sub-millisecond responses — with automatic, dependency-aware invalidation that keeps cached pages fresh and never leaks gated or authoring content to anonymous visitors."** Manifest advertises **1** screenshot (`docs/assets/marketplace/extension-card.jpg`) while `docs/screenshots.json` defines **7 required** captures — a mismatch (see §5).

## 2. Improvements (existing functionality)

1. **Replace blanket cache flush on model `created` and `Translation` `updated` with targeted invalidation** — every core model `created` event and every `Translation` `updated` calls `dispatchClearAllHtmlCache()`, which deletes the _entire_ `page_cache` disk and truncates `cached_model_urls` (`ClearAllHtmlCacheAction`). On a busy multi-site install, inserting one row of any registered model type cold-starts the whole cache and triggers a re-render storm. Creates of leaf content (e.g. a new comment-like row) rarely affect existing cached URLs. Scope new-record invalidation to route/structure models only, and fall through to dependency-indexed clears elsewhere. — `src/Providers/HtmlCacheServiceProvider.php` (`registerModelInvalidationHooks`) — M

2. **Make `cache_vary_headers` actually vary by the things the manifest claims** — `config/capell-html-cache.php` sets `cache_vary_headers => ['Accept-Encoding']` only, but the manifest declares `variesBy: ["site","locale"]`. Site/locale variance is currently _implicit_ (each locale is a distinct `SiteDomain` host/path). That holds only while every locale maps to a distinct host or path prefix; a locale served via cookie/header on a shared URL would collide. Document the host/path assumption explicitly and add `Vary` coverage (or an explicit eligibility bail) for any locale negotiation that is not host/path-based. — `config/capell-html-cache.php`, `src/Http/Middleware/HtmlCacheMiddleware.php` (`applyCacheHeaders`) — M

3. **Expose `cache_ttl` honestly in served headers** — the on-disk store has no TTL/expiry at all (files live until invalidated); `cache_ttl` is only used to derive the `s-maxage`/`stale-while-revalidate` CDN header, and even then `s-maxage` is `intdiv($cacheTtl, 6)` with a hard-coded `max-age=60` and `stale-while-revalidate=86400`. These magic divisors/constants are undocumented and surprising. Lift them into config (`shared_max_age`, `browser_max_age`, `stale_while_revalidate`) and document that the filesystem entry itself is TTL-less. — `src/Http/Middleware/HtmlCacheMiddleware.php` (`applyCacheHeaders`) — S

4. **Flesh out `HtmlCacheHealthCheck` to match its `critical` manifest claim** — the class implements only `compatibleCapellApiVersion()` and contains no checks, yet `capell.json` registers it `severity: critical` with the label "package surfaces, providers, and install health are discoverable by Diagnostics." It should at minimum assert: `page_cache` disk is writable, the `frontend.cache` middleware is wired into the frontend stack, the two tables exist, and the scheduled-invalidation command is registered when `mode=scheduled`. — `src/Health/HtmlCacheHealthCheck.php` — M

5. **De-duplicate the two cookie-stripping code paths** — `PreventSessionCookieOnCacheableRequests::stripSessionCookies()` and `HtmlCacheMiddleware::stripConfiguredCookies()` hold identical `[session.cookie, XSRF-TOKEN, PHPDEBUGBAR_STACK_DATA]` lists. Drift between them is a latent safety bug (a cookie stripped in one path but not the other). Extract a shared helper / config list. — `src/Http/Middleware/PreventSessionCookieOnCacheableRequests.php`, `src/Http/Middleware/HtmlCacheMiddleware.php` — S

6. **Reduce per-model-class closure registration overhead** — `registerModelInvalidationHooks()` iterates `CapellCore::getModels()` and registers up to 3 closures per class on every boot, and `ModelEventRegistrar` registers a `retrieved` hook per class per request. For installs with many morph-mapped models this is measurable boot/runtime cost against the `frontendRenderBudgetMs: 20` budget. Consider a single global model observer dispatching on `$model::class` rather than N closures. — `src/Providers/HtmlCacheServiceProvider.php`, `src/Support/ModelServing/ModelEventRegistrar.php` — M

7. **Document/guard the `static_generation.internal_requests` re-entrancy** — `StaticSiteCommand --internal` and scheduled refresh render _through the live kernel_; combined with the `retrieved` hook this re-enters `RetrievedModelStore` and re-dispatches `RegisterCachedModelUrlsJob` during generation. Confirm (and test) that generation-time renders don't recursively queue dependency jobs or strip cookies on the synthetic request. — `src/Console/Commands/StaticSiteCommand.php`, `src/Support/StaticSite/StaticSiteGenerator.php`, `src/Actions/RefreshCachedUrlAtomicallyAction.php` — M

## 3. Missing Features (gaps)

The single declared capability is `cache-blocking` — which describes the _extension veto_ mechanism, not the cache itself. For a foundation full-page-cache package the capability set and feature surface are thin against table-stakes:

- **Tag-based purge API / CDN integration (table-stakes, currently absent).** `Surrogate-Key` headers _are_ emitted (`HtmlCacheMiddleware::applySurrogateKey` — `page-{id}`, `lang-{code}`, extension tags) but **nothing consumes them**: there is no purge endpoint, no Fastly/Cloudflare/Varnish surrogate-key purge driver, no outbound webhook on invalidation. The package invalidates its own local disk only. A pluggable `CachePurger` contract with a null driver + one CDN driver would turn emitted surrogate keys into real edge invalidation. This is the biggest differentiator gap.
- **Per-locale / per-currency / per-segment variance hooks.** `variesBy` is host/path only. There is no extension point to add a vary dimension (e.g. currency cookie, A/B bucket) — meaning experiments/campaign-studio personalization must _disable_ caching entirely (via `cacheable:false`) rather than vary it. A registrable "cache variant key" contract would let those packages cache per-segment instead of opting out.
- **ESI / partial holes for dynamic fragments.** All-or-nothing: a single dynamic region forces the whole page uncacheable. No edge-side-include or placeholder-hole mechanism to cache the shell and hydrate dynamic bits. Differentiator for high-traffic sites with a personalized header/cart.
- **Stale-while-revalidate at the origin.** The `stale-while-revalidate` directive is emitted for CDNs, but the origin store has no SWR: a missed/invalidated entry blocks on a full render. Scheduled mode approximates this but only on a cron cadence. A lock+serve-stale-then-regenerate path would cut tail latency and prevent dogpiles.
- **Cache warming as a first-class, queued, incremental job.** Warming exists only as the synchronous `capell:static-site` command. No queued warm-after-deploy, no warm-top-N-URLs-by-traffic, no warm-on-invalidate. The stale queue is regeneration, not proactive warming.
- **Explicit bypass-rule configuration.** Bypass logic is hard-coded (query present, session cookie, `Authorization`, access-gate, `?without_html_cache`, `?signature`). There is no operator-facing allow/deny list of paths or cookies to never cache (e.g. `/cart`, `/account/*`). A config-driven bypass matcher is standard for full-page caches.
- **Cache hit-rate / coverage telemetry.** Widgets show counts and the stale queue, but there is no hit/miss ratio, no bytes-served, no per-URL hit counters. For a package whose entire value proposition is performance, the absence of a hit-rate metric undercuts the marketplace pitch (§5).

## 4. Issues / Risks

**Public-output safety (the crux) — assessed strong, with caveats.** Gated/personalized content is excluded on multiple independent layers and these are well tested (141 cases across 15 files):

- Authenticated/session bypass: `cache_skip_authenticated` + `hasIncomingSessionCookie` + `Authorization` header + `?signature` short-circuit both reads and writes. Tested ("bypasses cached html for requests with a session cookie", "...authenticated requests without a session cookie", "can serve cached html ... when configured").
- Access-gate bypass: `shouldBypassForAccessGate` checks request attribute, browser-token cookie, and an active `access_gate_areas` row. Tested ("bypasses cached html for access gated protected requests", "...browser token requests even when authenticated cache reads are enabled").
- Authoring-surface leak prevention: dual inspection via `PublicHtmlSafetyInspector::containsAuthoringSurface` in both `HtmlCacheMiddleware` and `PageCache::cache`, with an `xxh128`-hash optimization to skip re-inspection. Writes are skipped if markers present; responses are marked `BYPASS` + `private, no-store`. Tested ("blocks unsafe public html during stale refresh and keeps the old cache file").
- Extension veto: `ExtensionCacheSafetyResolver::isPublicCacheSafe()` blocks the whole response if any recorded extension contribution is non-cacheable/sensitive.

Caveats / risks to verify or close:

1. **`access_gate_areas` existence query runs on the hot path.** `hasActiveAccessGateArea()` issues `DB::table('access_gate_areas')->where('key',...)->where('status','active')->exists()` on cache-read decisions when no request attribute/cookie is present. This is a per-request DB query against the `frontendRenderBudgetMs: 20` budget and couples html-cache to access-gate's schema by string. Cache this lookup (request-scoped or short TTL) and guard the `Schema::hasTable` branch. — `src/Http/Middleware/HtmlCacheMiddleware.php`

2. **Manifest ↔ behaviour mismatch on `variesBy`.** Manifest says `variesBy: ["site","locale"]`; the on-disk key and `Vary` header encode neither directly (host/path only; `Vary: Accept-Encoding`). Safe under the "distinct host/path per locale" assumption, but undocumented and unenforced — a future shared-URL locale scheme would silently poison the cache. Add a test that asserts two locales on the same host+path are not served the same file (or are explicitly bypassed). (§2.2)

3. **`cacheSafety.cacheable: false` in the manifest, `capabilities: ["cache-blocking"]`.** For the package that _provides_ the cache this reads as self-contradictory and will confuse marketplace/diagnostics consumers. Clarify: the package's own _output_ is infrastructure (not user content), but the capability naming ("cache-blocking") describes the veto resolver, not caching. Consider a `full-page-cache` / `cache-provider` capability. (§5)

4. **Coarse invalidation → dogpile risk.** Per §2.1, frequent full flushes (`ClearAllHtmlCacheAction` deletes the entire disk + truncates the index) on creates/translation-updates can cause synchronized cold-cache re-render storms with no origin SWR/lock (§3). Highest performance risk.

5. **Path-traversal defence is string-replace, not canonicalization.** `HtmlCacheStore` strips `../`/`..\\` and `PageCache::safeRequestSegments` rejects `..` segments — reasonable, and there is an `__invalid__` fallback, but the defence is ad-hoc. A test fixture of hostile paths (encoded dots, null bytes, overlong) would harden the guarantee. Tested partially ("caches ... invalid request paths").

6. **Redis-cluster safety: not applicable but worth a note.** The cache layer is filesystem-based and the queues are DB tables, so none of the banned `Redis::scan/keys/flushdb` commands appear here. No action needed; call this out so reviewers don't assume a Redis store.

7. **Performance budgets are declared but unverified.** `frontendRenderBudgetMs: 20`, `adminQueryBudget: 40`. No test asserts the middleware decision path stays within budget, and items §4.1/§2.6 add per-request queries/closures that erode it. Add a budget assertion or benchmark.

8. **`composer.json` has no `test`/`analyse`/`lint` scripts.** Verification relies on monorepo-root tooling; the package can't be checked in isolation. Add package-local script aliases for portability.

## 5. Marketplace & Positioning

Foundation/bundled and free — correct: this is infrastructure every public Capell site needs, and it anchors the platform's performance story. The package copy now leads with the speed and public-output-safety outcomes.

- **Shipped `marketplace.summary`:** _"Serve Capell pages as static HTML for sub-millisecond responses — with automatic, dependency-aware invalidation that keeps cached pages fresh and never leaks gated or authoring content to anonymous visitors."_
- **Shipped composer/manifest `description`:** _"Full-page static HTML cache for Capell with dependency-indexed invalidation, scheduled stale-regeneration, and public-output safety guarantees."_
- **Tiering:** Keep the core free/foundation — it must ship by default for the platform to feel fast. The §3 differentiators (CDN/surrogate-key purge driver, per-segment variance, ESI holes, traffic-ranked warming, hit-rate telemetry) are credible **premium add-on** material ("HTML Cache Pro" / fold into frontend-optimizer's commercial tier). The safety mechanism and local cache stay free; edge integration and personalization-aware caching are the upsell.
- **Screenshot/media gap (action required):** `capell.json` lists **1** screenshot (`extension-card.jpg`); `docs/screenshots.json` specifies **7 required** captures (maintenance-cache-page, cached-model-urls, dashboard-widgets, site-health-cache-map, page-table-extension, public-cache-hit, maintenance-page). Generate the 7 and reconcile the manifest's `marketplace.screenshots` array to match. The "anonymous public cache hit with no authoring markers/cookies" shot is the single most persuasive safety+performance asset and should lead the gallery.
- **Performance anchor:** none of the marketing surfaces show a number. Pair the launch with a hit-rate/coverage widget (§3) so the marketplace can cite a real before/after TTFB or hit-ratio.
- **Keywords/tags:** `full-page-cache`, `html-cache`, `static-site-generation`, `cache-invalidation`, `surrogate-key`, `stale-while-revalidate`, `cdn-purge`, `performance`, `ttfb`, `public-output-safety`, `multi-site-cache`, `edge-cache`.

## 6. Prioritized Roadmap

| Item                                                                                      | Bucket | Effort | Impact | Section ref |
| ----------------------------------------------------------------------------------------- | ------ | ------ | ------ | ----------- |
| Targeted invalidation on `created` / `Translation` updated (stop full-disk flush)         | Now    | M      | High   | §2.1, §4.4  |
| Cache the `access_gate_areas` hot-path existence query                                    | Done   | S      | High   | §4.1 — Done 2026-06-04: memoized lookup plus refresh/disabled-cache tests. |
| Implement real `HtmlCacheHealthCheck` (writable disk, middleware wired, tables, schedule) | Now    | M      | High   | §2.4        |
| Reconcile manifest screenshots (1) with screenshots.json (7); capture all 7               | Now    | M      | High   | §5          |
| Rewrite marketplace summary + composer description (outcome-led)                          | Done   | S      | Med    | §5 — Shipped 2026-06-05: `capell.json` marketplace summary, manifest/composer descriptions, README, and docs index now use outcome-led speed + public-output-safety copy. |
| Cookie-strip list de-duped 2026-06-04; both strip paths tested                            | Done   | S      | Med    | §2.5        |
| Shipped 2026-06-04: add test/guard so same-host/path locale variants are not cross-served | Done   | S      | High   | §4.2        |
| `CachePurger` contract + null driver + one CDN surrogate-key purge driver                 | Next   | L      | High   | §3          |
| Config-driven bypass allow/deny path & cookie matcher                                     | Next   | M      | Med    | §3          |
| Origin stale-while-revalidate (lock + serve-stale-then-regenerate)                        | Next   | M      | High   | §3, §4.4    |
| Lift `s-maxage`/`max-age`/SWR magic numbers into config                                   | Next   | S      | Low    | §2.3        |
| Hit-rate / coverage telemetry widget + per-URL counters                                   | Next   | M      | Med    | §3, §5      |
| Single global model observer instead of N per-class closures                              | Next   | M      | Med    | §2.6        |
| Registrable per-segment cache-variant key contract (currency/AB)                          | Later  | L      | Med    | §3          |
| ESI / partial-hole hydration for dynamic fragments                                        | Later  | L      | Med    | §3          |
| Queued, traffic-ranked incremental cache warming (warm-on-deploy / warm-on-invalidate)    | Later  | L      | Med    | §3          |
| Hostile-path test fixtures + budget assertion for middleware decision path                | Later  | S      | Med    | §4.5, §4.7  |
