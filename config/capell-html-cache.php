<?php

declare(strict_types=1);

use Illuminate\Support\Env;

return [
    'enabled' => Env::get('CAPELL_HTML_CACHE', true),
    'write_enabled' => Env::get('CAPELL_WRITE_HTML_CACHE', true),
    'minify_html' => Env::get('CAPELL_MINIFY_HTML', true),
    'cache_ttl' => 3600,
    'http_cache' => [
        /*
        |--------------------------------------------------------------------------
        | Public response cache-control ages
        |--------------------------------------------------------------------------
        |
        | The filesystem page cache itself has no TTL; files live until model,
        | route, or manual invalidation clears or refreshes them. These values
        | only control HTTP/CDN cache headers on public cacheable responses.
        | When shared_max_age is null, it falls back to cache_ttl / 6 for
        | backwards-compatible headers.
        */
        'shared_max_age' => Env::get('CAPELL_HTML_CACHE_SHARED_MAX_AGE'),
        'browser_max_age' => (int) Env::get('CAPELL_HTML_CACHE_BROWSER_MAX_AGE', 60),
        'stale_while_revalidate' => (int) Env::get('CAPELL_HTML_CACHE_STALE_WHILE_REVALIDATE', 86400),
    ],
    'cache_vary_headers' => ['Accept-Encoding'],
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
    'model_event_registration_mode' => Env::get('CAPELL_MODEL_EVENT_REGISTRATION_MODE', 'deferred'),
    'static_generation' => [
        'internal_requests' => Env::get('CAPELL_STATIC_HTML_INTERNAL_REQUESTS', false),
    ],
    'site_health_public_html_scan_limit' => (int) Env::get('CAPELL_HTML_CACHE_SITE_HEALTH_SCAN_LIMIT', 100),
    'site_health_unindexed_public_html_scan_limit' => (int) Env::get('CAPELL_HTML_CACHE_SITE_HEALTH_UNINDEXED_SCAN_LIMIT', 25),
    'site_health_cached_url_limit' => (int) Env::get('CAPELL_HTML_CACHE_SITE_HEALTH_CACHED_URL_LIMIT', 20),
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
