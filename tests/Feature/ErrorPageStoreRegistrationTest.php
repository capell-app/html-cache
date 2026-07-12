<?php

declare(strict_types=1);

use Capell\Frontend\Contracts\StaticErrorPageStore;
use Capell\HtmlCache\Support\Error\HtmlCacheStaticErrorPageStore;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Illuminate\Support\Facades\Storage;

uses(HtmlCacheTestCase::class);

it('registers the frontend static error page store against the page cache disk', function (): void {
    Storage::fake('page_cache');

    $store = resolve(StaticErrorPageStore::class);

    expect($store)->toBeInstanceOf(HtmlCacheStaticErrorPageStore::class);

    $store->put('errors/https.example.test/404.html', '<h1>Not Found</h1>');

    expect($store->exists('errors/https.example.test/404.html'))->toBeTrue()
        ->and($store->path('errors/https.example.test/404.html'))->not->toBeNull()
        ->and(Storage::disk('page_cache')->get('errors/https.example.test/404.html'))->toBe('<h1>Not Found</h1>');
});
