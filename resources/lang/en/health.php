<?php

declare(strict_types=1);

return [
    'disk' => [
        'failed' => 'The page_cache disk could not be written to; cached HTML cannot be stored.',
        'label' => 'HTML cache disk writable',
        'passed' => 'The page_cache disk is configured and writable for static HTML output.',
        'remediation' => 'Ensure the page_cache filesystem disk is configured and its root directory is writable by the web server.',
    ],
    'middleware' => [
        'failed' => 'The frontend.cache middleware alias or frontend route stack entry is not wired to the HTML cache middleware; public pages will not be cached.',
        'label' => 'Frontend HTML cache middleware wired',
        'passed' => 'The frontend.cache middleware alias resolves to the HTML cache middleware and is present in the frontend route stack.',
        'remediation' => 'Ensure HtmlCacheServiceProvider registers the frontend.cache middleware alias and inserts it into the frontend route middleware registry.',
    ],
    'local_path' => [
        'failed' => 'The page_cache disk does not expose local filesystem paths required for atomic HTML writes.',
        'label' => 'HTML cache disk supports atomic local paths',
        'passed' => 'The page_cache disk exposes local filesystem paths for atomic HTML writes.',
        'remediation' => 'Use a local or shared POSIX filesystem disk. Object-storage drivers are not supported by the HTML file cache.',
    ],
    'stale_command' => [
        'failed' => 'Scheduled invalidation is active but the :command command is not registered.',
        'label' => 'Scheduled stale-regeneration command registered',
        'not_required' => 'Invalidation mode is instant; scheduled stale-regeneration is not required.',
        'passed' => 'Scheduled invalidation is active and the :command command is registered.',
        'remediation' => 'Ensure HtmlCacheServiceProvider registers the :command command while invalidation mode is scheduled.',
    ],
    'tables' => [
        'failed' => 'Missing tables: :tables.',
        'label' => 'HTML cache storage tables',
        'passed' => 'The HTML cache dependency index, stale queue, and static generation run tables are present.',
        'remediation' => 'Run the Capell migrations to create the HTML cache storage tables.',
    ],
];
