<?php

declare(strict_types=1);

use Illuminate\Support\Env;

$integerEnv = static function (string $key, int $default): int {
    $value = Env::get($key, $default);

    return is_numeric($value) ? (int) $value : $default;
};

return [
    'enabled' => Env::get('CAPELL_HTML_CACHE', true),
    'write_enabled' => Env::get('CAPELL_WRITE_HTML_CACHE', true),
    'minify_html' => Env::get('CAPELL_MINIFY_HTML', true),
    'cache_ttl' => 3600,
    'filesystem_ttl_seconds' => $integerEnv('CAPELL_HTML_CACHE_FILESYSTEM_TTL_SECONDS', 3600),
    'error_pages' => [
        'max_files_per_host' => $integerEnv('CAPELL_HTML_CACHE_MAX_ERROR_FILES_PER_HOST', 500),
        'retain_after_prune' => $integerEnv('CAPELL_HTML_CACHE_RETAIN_ERROR_FILES_AFTER_PRUNE', 450),
    ],
    'hit_recording' => [
        'enabled' => Env::get('CAPELL_HTML_CACHE_HIT_RECORDING', true),
        'flush_delay_seconds' => $integerEnv('CAPELL_HTML_CACHE_HIT_FLUSH_DELAY_SECONDS', 30),
        'buffer_ttl_seconds' => $integerEnv('CAPELL_HTML_CACHE_HIT_BUFFER_TTL_SECONDS', 3600),
    ],
    'http_cache' => [
        /*
        |--------------------------------------------------------------------------
        | Public response cache-control ages
        |--------------------------------------------------------------------------
        |
        | Filesystem entries are bounded independently by filesystem_ttl_seconds.
        | These values control HTTP/CDN cache headers on public responses.
        | When shared_max_age is null, it falls back to cache_ttl / 6 for
        | backwards-compatible headers.
        */
        'shared_max_age' => Env::get('CAPELL_HTML_CACHE_SHARED_MAX_AGE'),
        'browser_max_age' => (int) Env::get('CAPELL_HTML_CACHE_BROWSER_MAX_AGE', 60),
        'stale_while_revalidate' => (int) Env::get('CAPELL_HTML_CACHE_STALE_WHILE_REVALIDATE', 86400),
    ],
    'origin_stale_while_revalidate' => [
        'enabled' => Env::get('CAPELL_HTML_CACHE_ORIGIN_SWR', true),
    ],
    'request_coalescing' => [
        'enabled' => Env::get('CAPELL_HTML_CACHE_REQUEST_COALESCING', true),
        'lock_seconds' => $integerEnv('CAPELL_HTML_CACHE_COALESCING_LOCK_SECONDS', 15),
        'wait_seconds' => $integerEnv('CAPELL_HTML_CACHE_COALESCING_WAIT_SECONDS', 3),
    ],
    'cache_vary_headers' => ['Accept-Encoding'],
    'stateless_pagination' => [
        /*
        |--------------------------------------------------------------------------
        | Stateless, cacheable pagination
        |--------------------------------------------------------------------------
        |
        | Public listing components (theme/showcase galleries, marketplace browse,
        | blog/events archives) historically paginate and filter through stateful
        | Livewire write requests. On a publicly cached page the session and CSRF
        | cookies are stripped so the blob stays shareable, which makes those
        | writes fail CSRF and surface as a 419 "page has expired".
        |
        | When enabled, opted-in components paginate/filter via plain GET requests
        | (client-side for in-memory data, ?page=N fragments for DB-backed data)
        | so the interaction issues no Livewire write and the response stays
        | cacheable. Disable to restore the legacy stateful Livewire behaviour.
        |
        | "params" is the allow-list of query keys that may appear on a cacheable
        | request without vetoing the page cache; any key outside this list still
        | bypasses the cache as before.
        */
        'enabled' => Env::get('CAPELL_HTML_CACHE_STATELESS_PAGINATION', true),
        'max_query_length' => $integerEnv('CAPELL_HTML_CACHE_MAX_QUERY_LENGTH', 512),
        'max_parameters' => $integerEnv('CAPELL_HTML_CACHE_MAX_QUERY_PARAMETERS', 12),
        'max_variants_per_path' => $integerEnv('CAPELL_HTML_CACHE_MAX_VARIANTS_PER_PATH', 100),
        'params' => [
            'page', 'articles', 'article-archives', 'showcase_page',
            'theme_search', 'theme_tag', 'theme_tier', 'theme_sort', 'topic', 'month',
            'search', 'kind', 'tag', 'laravelVersion', 'filamentVersion', 'capellVersion',
            'surface', 'certification', 'capability', 'category', 'tier', 'freeOnly', 'sort',
        ],
    ],
    'purge' => [
        /*
        |--------------------------------------------------------------------------
        | Edge/CDN surrogate-key purge
        |--------------------------------------------------------------------------
        |
        | The null driver keeps local filesystem invalidation only. The http
        | driver sends normalized surrogate keys to a CDN, reverse proxy, or
        | webhook endpoint whenever cached URLs are cleared locally.
        */
        'driver' => Env::get('CAPELL_HTML_CACHE_PURGE_DRIVER', 'null'),
        'endpoint' => Env::get('CAPELL_HTML_CACHE_PURGE_ENDPOINT'),
        'token' => Env::get('CAPELL_HTML_CACHE_PURGE_TOKEN'),
        'method' => Env::get('CAPELL_HTML_CACHE_PURGE_METHOD', 'post'),
        'surrogate_key_header' => Env::get('CAPELL_HTML_CACHE_PURGE_HEADER', 'Surrogate-Key'),
        'timeout_seconds' => (int) Env::get('CAPELL_HTML_CACHE_PURGE_TIMEOUT_SECONDS', 5),
        'cloudflare' => [
            'zone_id' => Env::get('CAPELL_HTML_CACHE_CLOUDFLARE_ZONE_ID'),
        ],
    ],
    'deployment' => [
        /*
        |--------------------------------------------------------------------------
        | Deployment topology
        |--------------------------------------------------------------------------
        |
        | Declare the number of web nodes so Site Health can detect unsupported
        | node-local multi-node cache storage. Set shared_page_cache only when
        | every web and queue node mounts the same POSIX page_cache directory.
        */
        'web_node_count' => max(1, $integerEnv('CAPELL_HTML_CACHE_WEB_NODE_COUNT', 1)),
        'shared_page_cache' => Env::get('CAPELL_HTML_CACHE_SHARED_PAGE_CACHE', false),
    ],
    'cache_skip_authenticated' => true,
    'bypass' => [
        /*
        |--------------------------------------------------------------------------
        | Operator-controlled cache bypass rules
        |--------------------------------------------------------------------------
        |
        | Paths, cookie names, and header names support Laravel wildcard matching.
        | Use these for public URLs, personalization cookies, or locale/segment
        | headers that should never be read from or written to the shared HTML
        | cache, such as /cart, /account/*, currency, or Accept-Language.
        */
        'paths' => [],
        'cookies' => [],
        'headers' => [],
    ],
    'access_gate' => [
        'active_area_cache_seconds' => (int) Env::get('CAPELL_HTML_CACHE_ACCESS_GATE_AREA_CACHE_SECONDS', 5),
    ],
    'invalidation' => [
        'mode' => Env::get('CAPELL_HTML_CACHE_INVALIDATION_MODE', 'instant'),
        'schedule' => Env::get('CAPELL_HTML_CACHE_INVALIDATION_SCHEDULE', 'everyFiveMinutes'),
        'batch_size' => (int) Env::get('CAPELL_HTML_CACHE_INVALIDATION_BATCH_SIZE', 100),
        'processing_timeout_minutes' => (int) Env::get('CAPELL_HTML_CACHE_PROCESSING_TIMEOUT_MINUTES', 15),
        'retry_backoff_minutes' => (int) Env::get('CAPELL_HTML_CACHE_RETRY_BACKOFF_MINUTES', 5),
        'max_attempts' => (int) Env::get('CAPELL_HTML_CACHE_MAX_ATTEMPTS', 5),
    ],
    'retention' => [
        'processed_stale_days' => $integerEnv('CAPELL_HTML_CACHE_PROCESSED_STALE_RETENTION_DAYS', 7),
        'generation_run_days' => $integerEnv('CAPELL_HTML_CACHE_GENERATION_RUN_RETENTION_DAYS', 30),
    ],
    'model_event_registration_mode' => Env::get('CAPELL_MODEL_EVENT_REGISTRATION_MODE', 'deferred'),
    'static_generation' => [
        'internal_requests' => Env::get('CAPELL_STATIC_HTML_INTERNAL_REQUESTS', true),
    ],
    'site_health_public_html_scan_limit' => (int) Env::get('CAPELL_HTML_CACHE_SITE_HEALTH_SCAN_LIMIT', 100),
    'site_health_unindexed_public_html_scan_limit' => (int) Env::get('CAPELL_HTML_CACHE_SITE_HEALTH_UNINDEXED_SCAN_LIMIT', 25),
    'site_health_cached_url_limit' => $integerEnv('CAPELL_HTML_CACHE_SITE_HEALTH_CACHED_URL_LIMIT', 20),
    'public_html_authoring_markers' => [
        'data-capell-authoring',
        'data-capell-editable',
        'data-capell-editor',
        'data-capell-editor-url',
        'data-field-path',
        'data-model-id',
        'data-permission',
        'data-capell-package',
        '"field_path"',
        '"fieldpath"',
        '"model_id"',
        '"modelid"',
        '"editor_url"',
        '"editorurl"',
        '"signed_editor_url"',
        '"signededitorurl"',
        'capell-authoring',
        'capell-editor',
    ],
];
