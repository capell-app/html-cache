# Capell HTML Cache

`capell-app/html-cache` owns Capell's optional static HTML cache.

It provides:

- `cached_model_urls`, a table-backed morph index of cached URLs to rendered models.
- Runtime cache middleware aliases: `frontend.cache`, `frontend.model_events`, and `frontend.no_session_cookies_on_cache`.
- Cache clearing and static-site generation actions/jobs.
- The `capell:static-site` command.
- A Filament resource for inspecting and clearing cached model URLs.

Core, admin, and frontend do not import this package. They expose neutral extension points; this package registers the cache middleware, admin bridge, page table extender, and model invalidation hooks when installed.
