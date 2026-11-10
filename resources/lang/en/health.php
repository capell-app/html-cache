<?php

declare(strict_types=1);

return [
    'exhausted' => [
        'label' => 'HTML Cache: failed refreshes within limit',
        'passed' => 'No stale HTML cache URLs have exhausted their refresh attempts.',
        'failed' => ':count stale HTML cache URLs have exhausted their refresh attempts; previous HTML remains available.',
        'remediation' => 'Inspect the exhausted rows and their last_error in stale_cached_urls, repair the origin failure, then mark those URLs stale again or explicitly clear them.',
    ],
    'edge_purge_throttle' => [
        'label' => 'HTML Cache: edge purge pacing',
        'inactive' => 'Queued edge purges are sent directly: the configured purge driver has no rate-limited zone, or pacing is turned off.',
        'passed' => 'Edge zone :zone is purging at a learned :rate requests per second (ceiling :ceiling); :pending purges pending, oldest :age seconds; last provider status :status.',
        'paused' => 'Edge zone :zone was rate limited by the provider (status :status) and resumes in :waiting seconds at a learned :rate requests per second (ceiling :ceiling); :pending purges pending, oldest :age seconds.',
        'backlogged' => 'Edge zone :zone has :pending purges pending, the oldest for :age seconds, at a learned :rate requests per second (ceiling :ceiling); last provider status :status.',
        'backlogged_remediation' => 'Check that a queue worker is running and read the edge purge log entries for the provider status. Enable escalate_to_full_purge so an overdue backlog is replaced by one full-zone purge, or purge the zone manually.',
        'no_locks' => 'The edge purge pacing cache store does not support atomic locks, so queued purges are sent directly without coalescing or rate limiting.',
        'dropped' => 'Edge zone :zone gave up :count purges the provider refused as bad requests (last status :status, last at :when), so the edge may still serve stale copies of: :sample.',
        'dropped_remediation' => 'Read the edge purge error log entries for the refused URLs or tags and correct them. The warning clears when those URLs or tags are purged successfully, or after any successful full-zone purge, such as clearing the whole HTML cache with capell:html-cache:clear. A purge made outside Capell, such as in the provider dashboard, is not seen, so the warning then expires :lifetime after the last dropped purge.',
        'unavailable' => 'The edge purge pacing state could not be read from its cache store, so the learned rate and pending purges are unknown.',
        'no_locks_remediation' => 'Set capell-html-cache.purge.throttle.store to a cache store every queue worker shares that supports atomic locks, such as redis.',
    ],
    'disk' => [
        'failed' => 'The page_cache disk could not be written to; cached HTML cannot be stored.',
        'label' => 'HTML Cache: cache storage writable',
        'passed' => 'The page_cache disk is configured and writable for static HTML output.',
        'remediation' => 'Ensure the page_cache filesystem disk is configured and its root directory is writable by the web server.',
    ],
    'middleware' => [
        'failed' => 'The frontend.cache middleware alias or frontend route stack entry is not wired to the HTML cache middleware; public pages will not be cached.',
        'label' => 'HTML Cache: public page caching registered',
        'passed' => 'The frontend.cache middleware alias resolves to the HTML cache middleware and is present in the frontend route stack.',
        'remediation' => 'Ensure HtmlCacheServiceProvider registers the frontend.cache middleware alias and inserts it into the frontend route middleware registry.',
    ],
    'local_path' => [
        'failed' => 'The page_cache disk does not expose local filesystem paths required for atomic HTML writes.',
        'label' => 'HTML Cache: cache storage supports safe file writes',
        'passed' => 'The page_cache disk exposes local filesystem paths for atomic HTML writes.',
        'remediation' => 'Use a local or shared POSIX filesystem disk. Object-storage drivers are not supported by the HTML file cache.',
    ],
    'multi_node_purge' => [
        'failed' => 'HTML Cache is configured for :nodes web nodes, but page_cache is node-local and the :driver purge driver cannot invalidate peer nodes.',
        'label' => 'HTML Cache: cache clearing configured for every server',
        'single_node' => 'HTML Cache is configured for one web node, so local invalidation reaches the complete origin cache.',
        'non_local_disk' => 'HTML Cache is configured for :nodes web nodes and the page_cache disk uses the :driver driver. Confirm the separate local-path diagnostic before serving cached HTML.',
        'shared_disk' => 'HTML Cache is configured for :nodes web nodes using an acknowledged shared page_cache filesystem.',
        'edge_driver_node_local' => 'HTML Cache is configured for :nodes web nodes with the :driver edge purge driver, but page_cache is node-local: the purge empties the edge while the other nodes keep their own copies, so the next edge miss can serve withdrawn HTML.',
        'remediation' => 'Use one web node, or mount one shared POSIX page_cache directory on every web and queue node and set CAPELL_HTML_CACHE_SHARED_PAGE_CACHE=true. An HTTP or Cloudflare purge driver clears the edge in addition to that storage; it does not replace it. Node-local multi-node HTML caching is unsupported.',
    ],
    'stale_command' => [
        'failed' => 'Scheduled invalidation is active but the :command command is not registered.',
        'label' => 'HTML Cache: stale page refresh scheduled',
        'not_required' => 'Invalidation mode is instant; scheduled stale-regeneration is not required.',
        'passed' => 'Scheduled invalidation is active and the :command command is registered.',
        'remediation' => 'Ensure HtmlCacheServiceProvider registers the :command command while invalidation mode is scheduled.',
    ],
    'tables' => [
        'failed' => 'Missing tables: :tables.',
        'label' => 'HTML Cache: database tables present',
        'passed' => 'The HTML cache dependency index, stale queue, and static generation run tables are present.',
        'remediation' => 'Run the Capell migrations to create the HTML cache storage tables.',
    ],
];
