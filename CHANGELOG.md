# Changelog

All notable changes to `capell-app/html-cache` will be documented in this file.

## Unreleased

- Prepared package metadata and documentation for ongoing Capell 4.x package work.

## 2026-06-03

- Replaced the stub `HtmlCacheHealthCheck` with real diagnostics matching its `critical` manifest claim: probes the `page_cache` disk is writable, the `frontend.cache` middleware is wired, the `cached_model_urls` and `stale_cached_urls` tables exist, and the scheduled stale-regeneration command is registered when invalidation mode is `scheduled`.
- De-duplicated the two cookie-stripping code paths into a single `CacheableResponseCookieStripper`, removing the drift risk between `HtmlCacheMiddleware` and `PreventSessionCookieOnCacheableRequests` that could leak a session/CSRF cookie onto a cacheable response.
- Rewrote the marketplace summary and composer/manifest descriptions to lead with the performance and public-output-safety outcomes.
- Noted that `Surrogate-Key` headers are emitted for external CDN consumption but no built-in purge driver consumes them yet (a `CachePurger` contract and CDN driver are planned).
