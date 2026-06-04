# HTML Cache Invalidation

HTML Cache stores cached HTML files, an index of which models were seen while rendering each URL, and optionally a stale URL queue for scheduled refreshes. Clear or refresh both when a package changes content that may already be cached.

## Invalidation Modes

The default invalidation mode is `instant`, which keeps the existing behaviour: model and routing changes delete affected cached files immediately. Enable scheduled invalidation with:

```env
CAPELL_HTML_CACHE_INVALIDATION_MODE=scheduled
CAPELL_HTML_CACHE_INVALIDATION_SCHEDULE=everyFiveMinutes
CAPELL_HTML_CACHE_INVALIDATION_BATCH_SIZE=100
CAPELL_HTML_CACHE_PROCESSING_TIMEOUT_MINUTES=15
CAPELL_HTML_CACHE_RETRY_BACKOFF_MINUTES=5
CAPELL_HTML_CACHE_MAX_ATTEMPTS=5
```

In `scheduled` mode, model changes insert rows into `stale_cached_urls` instead of deleting cached files immediately. The scheduler runs `capell:html-cache:process-stale` on the configured cadence, renders fresh HTML through the Laravel kernel, and atomically replaces the existing cache file only after the refreshed response is safe and cacheable.

Site-domain scheme, host, path, site, and language mutations still clear the HTML cache immediately, even in scheduled mode. Those changes can make the old file path or public URL unsafe to serve while waiting for the next cycle.

Route/structure model creates and deletes still trigger broad invalidation because they can change public URL resolution. Non-route model creates and translation updates use the dependency index instead: only cached URLs that previously recorded that model are cleared in `instant` mode or marked stale in `scheduled` mode. This avoids cold-starting the full cache when a leaf record is created.

If a stale URL no longer resolves to an enabled site domain, the processor treats that as confirmation that the old public cache entry is obsolete, deletes the old cache files, and removes matching `cached_model_urls` rows.

Failed refreshes retry after the configured backoff until `max_attempts` is reached. Rows that keep failing are marked `exhausted` for diagnostics and manual follow-up instead of being retried forever.

## Main Actions

| Action                              | Use it when                                                                  |
| ----------------------------------- | ---------------------------------------------------------------------------- |
| `ClearCachedUrlAction`              | You know the public URL that should be removed from the cache.               |
| `ClearCachedPageUrlsAction`         | You have a collection of URLs and want a simple count of cleared entries.    |
| `ClearCachedUrlsForModelAction`     | You changed one model and want to clear every cached URL that referenced it. |
| `MarkCachedUrlStaleAction`          | You know a URL should be refreshed on the next scheduled cycle.              |
| `MarkCachedUrlsForModelStaleAction` | You changed one model and want scheduled mode to refresh indexed URLs.       |
| `MarkAllCachedUrlsStaleAction`      | Broad routing/domain changes should queue all indexed URLs as stale.         |
| `ProcessStaleHtmlCacheAction`       | Process pending stale URLs and atomically refresh their cached HTML.         |
| `RecordCachedModelUrlsAction`       | A render pass knows which models contributed to a cached URL.                |
| `GenerateStaticSiteAction`          | A full static generation run is needed for one `Site`.                       |
| `GenerateStaticSitesAction`         | Static generation should run for all selected sites.                         |

The admin cache map reads `cached_model_urls`; it does not scan public HTML on every request. If a package renders model-backed content but never records dependencies, cache invalidation can only work by URL.

## Clear One URL

```php
use Capell\HtmlCache\Actions\ClearCachedUrlAction;

ClearCachedUrlAction::run('https://example.test/about', refresh: true);
```

`refresh: true` dispatches `Capell\Core\Actions\VisitUrlAction` after the file and index rows are removed. Use it only when the URL should be warmed immediately.

## Clear Every URL For A Model

```php
use Capell\HtmlCache\Actions\ClearCachedUrlsForModelAction;

$cleared = ClearCachedUrlsForModelAction::run($article, refresh: false);
```

This looks up rows where `cacheable_type` matches `$article->getMorphClass()` and `cacheable_id` matches the model key. If the model was never recorded with `RecordCachedModelUrlsAction`, the action returns `0`.

## Record Dependencies During Rendering

```php
use Capell\HtmlCache\Actions\RecordCachedModelUrlsAction;

RecordCachedModelUrlsAction::run($url, [
    $article->getMorphClass() => [$article->getKey()],
    $author->getMorphClass() => [$author->getKey()],
]);
```

`RecordCachedModelUrlsAction` resolves the site domain and path from the URL, upserts the current dependencies, and removes stale dependencies for the same URL hash.

## Configuration

| Key                                                         | Purpose                                                                                                                                                                          |
| ----------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `capell-html-cache.enabled`                                 | Turns HTML cache behaviour on or off.                                                                                                                                            |
| `capell-html-cache.write_enabled`                           | Allows cache writes. Disable this when investigating output safety.                                                                                                              |
| `capell-html-cache.minify_html`                             | Controls minification before writing cached HTML.                                                                                                                                |
| `capell-html-cache.cache_ttl`                               | Backward-compatible source for shared HTTP cache age when no explicit `shared_max_age` is configured. Filesystem cache entries are invalidation-driven and do not expire by TTL. |
| `capell-html-cache.http_cache.shared_max_age`               | `s-maxage` value for public cached responses; defaults to `cache_ttl / 6` when unset.                                                                                            |
| `capell-html-cache.http_cache.browser_max_age`              | Browser `max-age` value for public cached responses.                                                                                                                             |
| `capell-html-cache.http_cache.stale_while_revalidate`       | CDN/browser `stale-while-revalidate` directive value.                                                                                                                            |
| `capell-html-cache.cache_skip_authenticated`                | Keeps authenticated responses out of the public cache.                                                                                                                           |
| `capell-html-cache.access_gate.active_area_cache_seconds`   | Short TTL for the access-gate active-area lookup used by anonymous cache decisions. Set `0` to disable.                                                                          |
| `capell-html-cache.invalidation.mode`                       | `instant` or `scheduled`. Default `instant`.                                                                                                                                     |
| `capell-html-cache.invalidation.schedule`                   | Scheduler frequency for stale processing. Default `everyFiveMinutes`.                                                                                                            |
| `capell-html-cache.invalidation.batch_size`                 | Default stale URL batch size.                                                                                                                                                    |
| `capell-html-cache.invalidation.processing_timeout_minutes` | Minutes before a `processing` stale row may be claimed again.                                                                                                                    |
| `capell-html-cache.invalidation.retry_backoff_minutes`      | Minutes before a failed stale row may be retried.                                                                                                                                |
| `capell-html-cache.invalidation.max_attempts`               | Maximum refresh attempts before a stale row becomes `exhausted`.                                                                                                                 |
| `capell-html-cache.model_event_registration_mode`           | Controls model event registration timing; default is `deferred`.                                                                                                                 |
| `capell-html-cache.static_generation.internal_requests`     | Lets static generation render through the current Laravel kernel.                                                                                                                |
| `capell-html-cache.public_html_authoring_markers`           | Strings used by diagnostics to detect authoring leakage in public HTML.                                                                                                          |

## Console

```bash
vendor/bin/pest packages/html-cache/tests --configuration=phpunit.xml
```

The package command is:

```text
capell:html-cache:process-stale {--limit=}
capell:static-site {--site=} {--internal} {--refresh}
```

`capell:html-cache:process-stale` is scheduled automatically when `capell-html-cache.invalidation.mode` is `scheduled`. `--limit` overrides the configured batch size for one run.

`--internal` renders through the current Laravel kernel. `--refresh` deletes affected cached files before rendering.

## Extension Point

Implement `Capell\HtmlCache\Contracts\PageCacheNotifiable` when a class needs to react after a page cache entry is recorded:

```php
use Capell\HtmlCache\Contracts\PageCacheNotifiable;
use Illuminate\Database\Eloquent\Model;

final class SearchIndexCacheNotifier implements PageCacheNotifiable
{
    public function notifyPageCached(Model $model): void
    {
        // Keep side effects small; this runs from cache recording paths.
    }
}
```

Keep notifications cheap. Cache writes happen on public page renders, so slow work belongs on a queue.

## Public Output Safety

HTML Cache must remain safe for anonymous visitors, signed-in users, admins, crawlers, and static exports. Do not put authoring attributes, model IDs, signed editor URLs, field paths, package names, or permission hints into cached HTML. If a package needs admin editing, use Frontend Authoring's post-load beacon.
