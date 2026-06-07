# Changelog

All notable changes to `capell-app/html-cache` will be documented in this file.

## Unreleased

- Prepared package metadata and documentation for ongoing Capell 4.x package work.
- Narrowed model-event invalidation so non-route model creates and translation updates clear or stale only URLs indexed against that model instead of flushing the entire HTML cache.
- Added short-lived access-gate active-area lookup caching to keep anonymous cache-read decisions off the access gate table hot path.
- Added configurable public HTTP cache-control ages (`shared_max_age`, `browser_max_age`, and `stale_while_revalidate`) and documented that filesystem cache files remain invalidation-driven rather than TTL-driven.
- Added a `CachePurger` contract with null and HTTP surrogate-key purge drivers, plus config for edge purge endpoint, token, method, header, and timeout.
- Added cache-hit telemetry for cached URLs, including hit counts, bytes served, last-hit timestamps, and dashboard coverage row output.
- Replaced per-model generic invalidation closures with a single wildcard Eloquent observer path while preserving explicit route-structure invalidation handlers.
- Tightened HTML cache health diagnostics so the critical check verifies the `frontend.cache` alias is present in the frontend route middleware registry and covers missing scheduled stale-processing command registration.

## 2026-06-03

- Replaced the stub `HtmlCacheHealthCheck` with real diagnostics matching its `critical` manifest claim: probes the `page_cache` disk is writable, the `frontend.cache` middleware is wired, the `cached_model_urls` and `stale_cached_urls` tables exist, and the scheduled stale-regeneration command is registered when invalidation mode is `scheduled`.
- De-duplicated the two cookie-stripping code paths into a single `CacheableResponseCookieStripper`, removing the drift risk between `HtmlCacheMiddleware` and `PreventSessionCookieOnCacheableRequests` that could leak a session/CSRF cookie onto a cacheable response.
- Rewrote the marketplace summary and composer/manifest descriptions to lead with the performance and public-output-safety outcomes.
- Noted that `Surrogate-Key` headers are emitted for external CDN consumption; this is now backed by the configurable HTTP purge driver.
