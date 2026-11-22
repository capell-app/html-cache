<?php

declare(strict_types=1);

use Illuminate\Support\Env;

$integerEnv = static function (string $key, int $default): int {
    $value = Env::get($key, $default);

    return is_numeric($value) ? (int) $value : $default;
};

$floatEnv = static function (string $key, float $default): float {
    $value = Env::get($key, $default);

    return is_numeric($value) ? (float) $value : $default;
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
        'sample_rate' => $integerEnv('CAPELL_HTML_CACHE_HIT_SAMPLE_RATE', 10),
        'flush_delay_seconds' => $integerEnv('CAPELL_HTML_CACHE_HIT_FLUSH_DELAY_SECONDS', 30),
        'buffer_ttl_seconds' => $integerEnv('CAPELL_HTML_CACHE_HIT_BUFFER_TTL_SECONDS', 3600),
    ],
    'observation_recording' => [
        'enabled' => Env::get('CAPELL_HTML_CACHE_OBSERVATIONS', true),
        'sample_rate' => $integerEnv('CAPELL_HTML_CACHE_OBSERVATION_SAMPLE_RATE', 10),
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
        // Applies only while a ready edge purge driver removes withdrawn pages.
        'stale_while_revalidate' => (int) Env::get('CAPELL_HTML_CACHE_STALE_WHILE_REVALIDATE', 86400),
        // Ceiling without a ready edge purge: a withdrawn page may stay in shared caches for
        // s-maxage plus this many seconds, because nothing purges it.
        'unpurged_stale_while_revalidate' => $integerEnv('CAPELL_HTML_CACHE_UNPURGED_STALE_WHILE_REVALIDATE', 60),
    ],
    'origin_stale_while_revalidate' => [
        'enabled' => Env::get('CAPELL_HTML_CACHE_ORIGIN_SWR', true),
        'connection' => Env::get('CAPELL_HTML_CACHE_ORIGIN_SWR_CONNECTION'),
        'dispatch_interval_seconds' => $integerEnv('CAPELL_HTML_CACHE_ORIGIN_SWR_DISPATCH_INTERVAL_SECONDS', 30),
        // Site-wide changes keep stale copies only while a worker on the
        // refresh connection has polled within this many seconds; otherwise
        // every copy is cleared so a theme or critical CSS change cannot be
        // withheld by a missing worker. Scheduled invalidation mode skips this
        // heartbeat check and the overdue-stale expiry below: the scheduler,
        // not a queue worker, owns refreshing every stale copy there.
        'worker_heartbeat_seconds' => $integerEnv('CAPELL_HTML_CACHE_ORIGIN_SWR_WORKER_HEARTBEAT_SECONDS', 120),
        // A stale copy still waiting for a refresh after this long is cleared
        // instead of served (not applied in scheduled invalidation mode).
        'max_stale_age_seconds' => $integerEnv('CAPELL_HTML_CACHE_ORIGIN_SWR_MAX_STALE_AGE_SECONDS', 900),
        // How many background origin refreshes may render at once. Refreshes for
        // different URLs and sites share these slots; a deployment refresher and
        // the overdue-stale expiry take all of them. A changed value takes full
        // effect once the queues have drained.
        'refresh_slots' => $integerEnv('CAPELL_HTML_CACHE_ORIGIN_REFRESH_SLOTS', 3),
        // A deployment refresher holds the whole origin refresh lock while it
        // renews it, but never past this absolute limit from when it took it.
        // Beyond it the lock lapses, background refreshes resume and
        // maximum-age enforcement clears whatever is still stale.
        'refresher_hard_cap_seconds' => $integerEnv('CAPELL_HTML_CACHE_ORIGIN_REFRESHER_HARD_CAP_SECONDS', 1200),
    ],
    'request_coalescing' => [
        'enabled' => Env::get('CAPELL_HTML_CACHE_REQUEST_COALESCING', true),
        'lock_seconds' => $integerEnv('CAPELL_HTML_CACHE_COALESCING_LOCK_SECONDS', 15),
        'wait_seconds' => $integerEnv('CAPELL_HTML_CACHE_COALESCING_WAIT_SECONDS', 3),
        'uncacheable_seconds' => $integerEnv('CAPELL_HTML_CACHE_COALESCING_UNCACHEABLE_SECONDS', 5),
    ],
    /*
    | Uncached renders are heavy (seconds each) and share the PHP-FPM pool with
    | cache hits. After a release switch or full purge, many distinct cold URLs
    | can arrive at once; without a budget they occupy every worker. At most
    | max_concurrent renders run at once; further misses wait up to
    | wait_seconds for a slot, then receive a short private 503 with
    | Retry-After. Cached and stale copies are always served without a slot.
    | The default is half of CAPELL_FPM_MAX_CHILDREN (set by deployment from
    | the pool's pm.max_children), falling back to half of 8.
    |
    | Worst-case FPM worker hold for one cold miss: request_coalescing
    | wait_seconds (3 s) waiting on the render lock holder, plus this
    | render_budget wait_seconds (2 s) waiting for a render slot, so up to 5 s
    | before the 503, excluding the render itself once a slot is held. Size
    | the pool for max_concurrent renders plus the waiters this allows.
    */
    'render_budget' => [
        'enabled' => Env::get('CAPELL_HTML_CACHE_RENDER_BUDGET', true),
        'max_concurrent' => $integerEnv(
            'CAPELL_HTML_CACHE_MAX_CONCURRENT_RENDERS',
            max(1, intdiv($integerEnv('CAPELL_FPM_MAX_CHILDREN', 8), 2)),
        ),
        'wait_seconds' => $integerEnv('CAPELL_HTML_CACHE_RENDER_BUDGET_WAIT_SECONDS', 2),
    ],
    /*
    | Public responses always carry Cache-Tag, which Cloudflare consumes and strips
    | at the edge. Surrogate-Key is not stripped by most CDNs and would expose
    | page and site IDs and internal markers to visitors, so it is only emitted
    | when the proxy in front of the origin removes it before the client.
    | Purge requests are unaffected: they build their keys independently.
    */
    'expose_surrogate_key_header' => Env::get('CAPELL_HTML_CACHE_EXPOSE_SURROGATE_KEY', false),
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
        'required' => Env::get('CAPELL_HTML_CACHE_PURGE_REQUIRED', false),
        'endpoint' => Env::get('CAPELL_HTML_CACHE_PURGE_ENDPOINT'),
        'token' => Env::get('CAPELL_HTML_CACHE_PURGE_TOKEN'),
        'method' => Env::get('CAPELL_HTML_CACHE_PURGE_METHOD', 'post'),
        'surrogate_key_header' => Env::get('CAPELL_HTML_CACHE_PURGE_HEADER', 'Surrogate-Key'),
        'timeout_seconds' => (int) Env::get('CAPELL_HTML_CACHE_PURGE_TIMEOUT_SECONDS', 5),
        'cloudflare' => [
            'zone_id' => Env::get('CAPELL_HTML_CACHE_CLOUDFLARE_ZONE_ID'),
        ],
        /*
        |--------------------------------------------------------------------------
        | Queued edge purge throttling
        |--------------------------------------------------------------------------
        |
        | Queued purges are coalesced per edge zone for a short window, sent in
        | provider-sized batches and paced by a token bucket shared through the
        | cache store, so parallel queue workers cannot burst together. The
        | rate is a ceiling: a 429, a Retry-After or an exhausted rate-limit
        | header halves the learned rate, and sustained success raises it
        | again. The learned rate is kept in the cache so restarts start from
        | what the provider last accepted. The store must support atomic locks
        | and be shared by every queue worker (redis in production).
        */
        'throttle' => [
            'enabled' => Env::get('CAPELL_HTML_CACHE_PURGE_THROTTLE', true),
            'store' => Env::get('CAPELL_HTML_CACHE_PURGE_THROTTLE_STORE'),
            'log_channel' => Env::get('CAPELL_HTML_CACHE_PURGE_LOG_CHANNEL'),
            'coalesce_window_seconds' => $integerEnv('CAPELL_HTML_CACHE_PURGE_COALESCE_SECONDS', 2),
            // Cloudflare accepts up to 100 URLs or tags per purge request on every plan.
            'max_urls_per_request' => $integerEnv('CAPELL_HTML_CACHE_PURGE_MAX_URLS_PER_REQUEST', 100),
            'max_tags_per_request' => $integerEnv('CAPELL_HTML_CACHE_PURGE_MAX_TAGS_PER_REQUEST', 100),
            'requests_per_second' => $floatEnv('CAPELL_HTML_CACHE_PURGE_REQUESTS_PER_SECOND', 2.0),
            'burst' => $integerEnv('CAPELL_HTML_CACHE_PURGE_BURST', 5),
            'min_requests_per_second' => $floatEnv('CAPELL_HTML_CACHE_PURGE_MIN_REQUESTS_PER_SECOND', 0.05),
            'decrease_factor' => $floatEnv('CAPELL_HTML_CACHE_PURGE_DECREASE_FACTOR', 0.5),
            'increase_step' => $floatEnv('CAPELL_HTML_CACHE_PURGE_INCREASE_STEP', 0.1),
            'increase_after_successes' => $integerEnv('CAPELL_HTML_CACHE_PURGE_INCREASE_AFTER_SUCCESSES', 10),
            'learned_rate_ttl_seconds' => $integerEnv('CAPELL_HTML_CACHE_PURGE_LEARNED_RATE_TTL_SECONDS', 604800),
            'default_retry_after_seconds' => $integerEnv('CAPELL_HTML_CACHE_PURGE_DEFAULT_RETRY_AFTER_SECONDS', 30),
            'max_retry_after_seconds' => $integerEnv('CAPELL_HTML_CACHE_PURGE_MAX_RETRY_AFTER_SECONDS', 900),
            'failure_backoff_seconds' => $integerEnv('CAPELL_HTML_CACHE_PURGE_FAILURE_BACKOFF_SECONDS', 30),
            'max_batches_per_drain' => $integerEnv('CAPELL_HTML_CACHE_PURGE_MAX_BATCHES_PER_DRAIN', 50),
            // A batch the provider refuses (not rate limiting) moves behind the
            // other pending purges, and is dropped with a log entry after this
            // many refusals so it cannot block the zone.
            'max_batch_failures' => $integerEnv('CAPELL_HTML_CACHE_PURGE_MAX_BATCH_FAILURES', 5),
            // How long Site Health keeps warning about dropped purges when no
            // later purge covers them, e.g. after a purge in the provider dashboard.
            'dropped_record_ttl_seconds' => $integerEnv('CAPELL_HTML_CACHE_PURGE_DROPPED_RECORD_TTL_SECONDS', 604800),
            'drain_retry_until_seconds' => $integerEnv('CAPELL_HTML_CACHE_PURGE_DRAIN_RETRY_UNTIL_SECONDS', 7200),
            // Replace the pending purges with one full-zone purge when sending
            // them would take more than this many provider requests, or the
            // oldest has waited too long. Tag purges are the scarce allowance
            // (Cloudflare Free: 5 a minute), so count requests, not items.
            'escalate_to_full_purge' => Env::get('CAPELL_HTML_CACHE_PURGE_ESCALATE', true),
            'escalate_pending_threshold' => $integerEnv('CAPELL_HTML_CACHE_PURGE_ESCALATE_PENDING_THRESHOLD', 50),
            'escalate_after_seconds' => $integerEnv('CAPELL_HTML_CACHE_PURGE_ESCALATE_AFTER_SECONDS', 900),
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
        // Shared cache nodes must use the same persistent POSIX lock file,
        // outside the page-cache tree so clearing pages never unlinks its inode.
        'publication_lock_path' => Env::get('CAPELL_HTML_CACHE_PUBLICATION_LOCK_PATH'),
    ],
    'cache_skip_authenticated' => true,
    'anonymous_session_cookie' => [
        /*
        | Anonymous session cookies may use shared cache reads only on shells
        | that the consuming application has proved state-free. Authenticated
        | users always retain the normal private bypass.
        */
        'shared_paths' => [],
        'private_paths' => [],
    ],
    'internal_bypass' => [
        'header' => null,
        'secret' => null,
        'query_parameter' => 'without_html_cache',
        'allow_query_in_production' => false,
    ],
    'internal_shell' => ['header' => null],
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
        'ignored_query_parameters' => [],
        'ignored_query_parameter_except_paths' => [],
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
        // Render and publication lock files unused for this long are pruned daily.
        'lock_file_days' => $integerEnv('CAPELL_HTML_CACHE_LOCK_FILE_RETENTION_DAYS', 7),
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

    /*
    |--------------------------------------------------------------------------
    | Vary request headers
    |--------------------------------------------------------------------------
    |
    | Request headers whose values separate stored responses for the same URL,
    | such as 'Accept-Language' for sites that negotiate the language from the
    | request. Leave empty (the default) so each URL stores one file that a web
    | server can serve directly; a configured header appends a variant suffix to
    | the stored name and every variant is invalidated together.
    |
    */
    'cache_vary_headers' => [],
];
