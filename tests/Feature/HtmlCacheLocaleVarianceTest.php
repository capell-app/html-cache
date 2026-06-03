<?php

declare(strict_types=1);

use Capell\Core\Models\SiteDomain;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Support\Cache\PageCache;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

uses(HtmlCacheTestCase::class);

beforeEach(function (): void {
    config()->set('capell-html-cache.enabled', true);
    config()->set('capell-html-cache.write_enabled', true);
});

/**
 * The on-disk cache key is host+path only (no locale dimension). Locale variance
 * is therefore only safe when each locale maps to a distinct host or path prefix.
 * A locale negotiated on a shared URL via a query string must never be served the
 * same cached file; the query-present guard enforces this by bypassing the cache
 * entirely so two locales on the same host+path are never cross-served.
 */
it('does not cache or cross-serve two locales negotiated on the same host and path via query string', function (): void {
    Storage::fake('page_cache');

    $siteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => null,
    ]);

    $englishRequest = Request::create('https://example.test/about?lang=en', Symfony\Component\HttpFoundation\Request::METHOD_GET);
    app()->instance('request', $englishRequest);

    $englishResponse = resolve(HtmlCacheMiddleware::class)->handle(
        $englishRequest,
        fn (): Response => response('english about page', 200, ['Content-Type' => 'text/html']),
    );

    // Query-present requests are never written to the cache, so a second locale
    // on the same host+path cannot be served the first locale's cached file.
    expect($englishResponse->getContent())->toBe('english about page')
        ->and($englishResponse->headers->get('X-Frontend-Cache'))->toBeNull()
        ->and((string) $englishResponse->headers->get('Cache-Control'))->toContain('no-store')
        ->and(Storage::disk('page_cache')->allFiles())->toBe([]);

    $frenchRequest = Request::create('https://example.test/about?lang=fr', Symfony\Component\HttpFoundation\Request::METHOD_GET);
    app()->instance('request', $frenchRequest);

    $frenchResponse = resolve(HtmlCacheMiddleware::class)->handle(
        $frenchRequest,
        fn (): Response => response('french about page', 200, ['Content-Type' => 'text/html']),
    );

    expect($frenchResponse->getContent())->toBe('french about page')
        ->and($frenchResponse->headers->get('X-Frontend-Cache'))->toBeNull();
});

it('serves a distinct cached file per host so distinct-host locales do not collide', function (): void {
    Storage::fake('page_cache');

    SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'en.example.test',
        'path' => null,
    ]);
    SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'fr.example.test',
        'path' => null,
    ]);

    $englishRequest = Request::create('https://en.example.test/about', Symfony\Component\HttpFoundation\Request::METHOD_GET);
    app()->instance('request', $englishRequest);
    resolve(PageCache::class)->cache($englishRequest, response('english about page', 200, ['Content-Type' => 'text/html']));

    $frenchRequest = Request::create('https://fr.example.test/about', Symfony\Component\HttpFoundation\Request::METHOD_GET);
    app()->instance('request', $frenchRequest);
    resolve(PageCache::class)->cache($frenchRequest, response('french about page', 200, ['Content-Type' => 'text/html']));

    expect(Storage::disk('page_cache')->exists('https.en.example.test/about.html'))->toBeTrue()
        ->and(Storage::disk('page_cache')->exists('https.fr.example.test/about.html'))->toBeTrue()
        ->and(Storage::disk('page_cache')->get('https.en.example.test/about.html'))->toBe('english about page')
        ->and(Storage::disk('page_cache')->get('https.fr.example.test/about.html'))->toBe('french about page');
});
