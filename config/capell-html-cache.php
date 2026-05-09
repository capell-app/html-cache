<?php

declare(strict_types=1);

return [
    'enabled' => env('CAPELL_HTML_CACHE', true),
    'write_enabled' => env('CAPELL_WRITE_HTML_CACHE', true),
    'minify_html' => env('CAPELL_MINIFY_HTML', true),
    'cache_ttl' => 3600,
    'cache_vary_headers' => ['Accept-Encoding'],
    'cache_skip_authenticated' => true,
    'model_event_registration_mode' => env('CAPELL_MODEL_EVENT_REGISTRATION_MODE', 'deferred'),
    'static_generation' => [
        'internal_requests' => env('CAPELL_STATIC_HTML_INTERNAL_REQUESTS', false),
    ],
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
