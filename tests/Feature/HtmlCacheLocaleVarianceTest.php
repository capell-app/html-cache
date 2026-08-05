<?php

declare(strict_types=1);

use Capell\Core\Models\SiteDomain;
use Capell\Frontend\Support\Locale\FrontendLocaleScope;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Support\Cache\PageCache;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(HtmlCacheTestCase::class);

beforeEach(function (): void {
    config()->set('capell-html-cache.enabled', true);
    config()->set('capell-html-cache.write_enabled', true);
});

function useIsolatedLocaleVariancePageCacheDisk(): void
{
    $root = storage_path('framework/testing/disks/page_cache-locale-variance/' . Str::uuid()->toString());
    File::ensureDirectoryExists($root);

    Storage::set('page_cache', Storage::build([
        'driver' => 'local',
        'root' => $root,
        'throw' => true,
    ]));
}

function localeVarianceTranslationsAvailable(): bool
{
    return class_exists(FrontendLocaleScope::class);
}

function registerLocaleVarianceTranslations(): void
{
    app('translator')->addLines(['locale-variance.greeting' => 'Hello'], 'en');
    app('translator')->addLines(['locale-variance.greeting' => 'Bonjour'], 'fr');
}

/**
 * Stands in for the frontend kernel: `frontend.resolve` runs INSIDE the HTML
 * cache middleware, so the site locale is only ever applied on the cache-miss
 * render path. Everything locale-dependent must therefore be baked into the
 * bytes here, never re-derived when those bytes are replayed.
 */
function localeVarianceRenderer(string $locale): Closure
{
    return function () use ($locale): Response {
        resolve(FrontendLocaleScope::class)->apply($locale);

        $content = sprintf(
            '<html lang="%s"><body><p>%s</p><p>%s</p></body></html>',
            app()->getLocale(),
            __('locale-variance.greeting'),
            (new CarbonImmutable('2026-01-05'))->translatedFormat('l j F Y'),
        );

        return response($content, 200, ['Content-Type' => 'text/html']);
    };
}

function localeVarianceSiteDomain(string $domain): SiteDomain
{
    return SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => $domain,
        'path' => null,
    ]);
}

/**
 * @param  array<string, string>  $headers
 */
function localeVarianceHandle(string $url, Closure $renderer, array $headers = []): Symfony\Component\HttpFoundation\Response
{
    $request = Request::create($url, Symfony\Component\HttpFoundation\Request::METHOD_GET);

    foreach ($headers as $name => $value) {
        $request->headers->set($name, $value);
    }

    app()->instance('request', $request);

    return resolve(HtmlCacheMiddleware::class)->handle($request, $renderer);
}

/**
 * The on-disk cache key is host+path only (no locale dimension). Locale variance
 * is therefore only safe when each locale maps to a distinct host or path prefix.
 * A locale negotiated on a shared URL via a query string must never be served the
 * same cached file; the query-present guard enforces this by bypassing the cache
 * entirely so two locales on the same host+path are never cross-served.
 */
it('does not cache or cross-serve two locales negotiated on the same host and path via query string', function (): void {
    useIsolatedLocaleVariancePageCacheDisk();

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

it('bypasses same host and path locale variants negotiated by configured headers', function (): void {
    useIsolatedLocaleVariancePageCacheDisk();
    config()->set('capell-html-cache.bypass.headers', ['Accept-Language']);

    $siteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => null,
    ]);

    $englishRequest = Request::create('https://example.test/about', Symfony\Component\HttpFoundation\Request::METHOD_GET);
    app()->instance('request', $englishRequest);
    config()->set('capell-html-cache.bypass.headers', []);
    resolve(PageCache::class)->cache($englishRequest, response('english about page', 200, ['Content-Type' => 'text/html']));
    config()->set('capell-html-cache.bypass.headers', ['Accept-Language']);

    $frenchRequest = Request::create('https://example.test/about', Symfony\Component\HttpFoundation\Request::METHOD_GET);
    $frenchRequest->headers->set('Accept-Language', 'fr');

    app()->instance('request', $frenchRequest);

    $frenchResponse = resolve(HtmlCacheMiddleware::class)->handle(
        $frenchRequest,
        fn (): Response => response('french about page', 200, ['Content-Type' => 'text/html']),
    );

    expect($frenchResponse->getContent())->toBe('french about page')
        ->and($frenchResponse->headers->get('X-Frontend-Cache'))->toBeNull()
        ->and((string) $frenchResponse->headers->get('Cache-Control'))->toContain('no-store')
        ->and(Storage::disk('page_cache')->get('https.example.test/about.html'))->toBe('english about page');
});

it('serves a distinct cached file per host so distinct-host locales do not collide', function (): void {
    useIsolatedLocaleVariancePageCacheDisk();

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

it('replays a non default language page from the cache byte for byte', function (): void {
    useIsolatedLocaleVariancePageCacheDisk();
    registerLocaleVarianceTranslations();
    localeVarianceSiteDomain('fr.example.test');

    $missResponse = localeVarianceHandle('https://fr.example.test/about', localeVarianceRenderer('fr'));
    $missBody = (string) $missResponse->getContent();

    resolve(FrontendLocaleScope::class)->restore();

    $hitResponse = localeVarianceHandle('https://fr.example.test/about', localeVarianceRenderer('fr'));

    expect($missResponse->headers->get('X-Frontend-Cache'))->toBe('MISS');
    expect($hitResponse->headers->get('X-Frontend-Cache'))->toBe('HIT');
    expect($missBody)->toContain('Bonjour');
    expect($missBody)->toContain('lundi');
    // Byte equality, not containment: any locale-dependent output re-derived on
    // the replay path instead of baked into the stored bytes would diverge here.
    expect((string) $hitResponse->getContent())->toBe($missBody);
})->skip(fn (): bool => ! localeVarianceTranslationsAvailable(), 'Frontend locale scope is not present in the resolved capell-app/frontend.');

it('serves correct non default language bytes from cache while the application locale stays default', function (): void {
    useIsolatedLocaleVariancePageCacheDisk();
    registerLocaleVarianceTranslations();
    localeVarianceSiteDomain('fr.example.test');

    localeVarianceHandle('https://fr.example.test/about', localeVarianceRenderer('fr'));
    resolve(FrontendLocaleScope::class)->restore();

    $defaultLocale = app()->getLocale();

    $hitResponse = localeVarianceHandle(
        'https://fr.example.test/about',
        fn (): Response => response('origin must not run', 200, ['Content-Type' => 'text/html']),
    );

    // `frontend.cache` is registered outside `frontend.resolve`, so a cache hit
    // short-circuits before the kernel — and therefore before ApplyLocaleStep.
    // The application locale legitimately stays on the default while French
    // bytes are served, because the locale was already applied when those bytes
    // were rendered. Nothing on the replay path may depend on the live locale.
    expect($hitResponse->headers->get('X-Frontend-Cache'))->toBe('HIT');
    expect((string) $hitResponse->getContent())->toContain('Bonjour');
    expect(app()->getLocale())->toBe($defaultLocale);
})->skip(fn (): bool => ! localeVarianceTranslationsAvailable(), 'Frontend locale scope is not present in the resolved capell-app/frontend.');

it('does not bleed a non default site locale into the next request in the same process', function (): void {
    useIsolatedLocaleVariancePageCacheDisk();
    registerLocaleVarianceTranslations();
    localeVarianceSiteDomain('fr.example.test');
    localeVarianceSiteDomain('en.example.test');

    $defaultLocale = app()->getLocale();

    localeVarianceHandle('https://fr.example.test/about', localeVarianceRenderer('fr'));

    expect(app()->getLocale())->toBe('fr');

    // The request boundary, not a hand-rolled reset: FrontendLocaleScope registers
    // its restore through `app()->terminating()`.
    app()->terminate();

    expect(app()->getLocale())->toBe($defaultLocale);

    $englishResponse = localeVarianceHandle('https://en.example.test/about', localeVarianceRenderer('en'));
    $englishBody = (string) $englishResponse->getContent();

    expect($englishBody)->toContain('Hello');
    expect($englishBody)->toContain('Monday');
    expect($englishBody)->not->toContain('Bonjour');
})->skip(fn (): bool => ! localeVarianceTranslationsAvailable(), 'Frontend locale scope is not present in the resolved capell-app/frontend.');

it('keeps two language sites in separate cache entries and replays each in its own language', function (): void {
    useIsolatedLocaleVariancePageCacheDisk();
    registerLocaleVarianceTranslations();
    localeVarianceSiteDomain('fr.example.test');
    localeVarianceSiteDomain('en.example.test');

    $frenchMiss = (string) localeVarianceHandle('https://fr.example.test/about', localeVarianceRenderer('fr'))->getContent();
    resolve(FrontendLocaleScope::class)->restore();

    $englishMiss = (string) localeVarianceHandle('https://en.example.test/about', localeVarianceRenderer('en'))->getContent();
    resolve(FrontendLocaleScope::class)->restore();

    $frenchHit = localeVarianceHandle('https://fr.example.test/about', localeVarianceRenderer('fr'));
    resolve(FrontendLocaleScope::class)->restore();

    $englishHit = localeVarianceHandle('https://en.example.test/about', localeVarianceRenderer('en'));

    expect($frenchHit->headers->get('X-Frontend-Cache'))->toBe('HIT');
    expect($englishHit->headers->get('X-Frontend-Cache'))->toBe('HIT');
    expect((string) $frenchHit->getContent())->toBe($frenchMiss);
    expect((string) $englishHit->getContent())->toBe($englishMiss);
    expect($frenchMiss)->not->toBe($englishMiss);
    expect(Storage::disk('page_cache')->get('https.fr.example.test/about.html'))->toBe($frenchMiss);
    expect(Storage::disk('page_cache')->get('https.en.example.test/about.html'))->toBe($englishMiss);
})->skip(fn (): bool => ! localeVarianceTranslationsAvailable(), 'Frontend locale scope is not present in the resolved capell-app/frontend.');

it('ignores accept language entirely for the cache key and the cached bytes', function (): void {
    useIsolatedLocaleVariancePageCacheDisk();
    registerLocaleVarianceTranslations();
    localeVarianceSiteDomain('fr.example.test');

    $missBody = (string) localeVarianceHandle(
        'https://fr.example.test/about',
        localeVarianceRenderer('fr'),
        ['Accept-Language' => 'de-DE,de;q=0.9'],
    )->getContent();

    resolve(FrontendLocaleScope::class)->restore();

    // The locale must stay a pure function of host+path. If a future negotiation
    // feature ever lets Accept-Language reach the render, these hits diverge and
    // the shared cache starts cross-serving languages on one key.
    foreach (['en-GB,en;q=0.9', 'fr-FR,fr;q=0.8', 'ja;q=0.5', ''] as $acceptLanguage) {
        $hitResponse = localeVarianceHandle(
            'https://fr.example.test/about',
            fn (): Response => response('origin must not run', 200, ['Content-Type' => 'text/html']),
            $acceptLanguage === '' ? [] : ['Accept-Language' => $acceptLanguage],
        );

        expect($hitResponse->headers->get('X-Frontend-Cache'))->toBe('HIT');
        expect((string) $hitResponse->getContent())->toBe($missBody);
        expect((string) $hitResponse->headers->get('Vary'))->not->toContain('Accept-Language');
    }

    expect(Storage::disk('page_cache')->allFiles())->toBe(['https.fr.example.test/about.html']);
})->skip(fn (): bool => ! localeVarianceTranslationsAvailable(), 'Frontend locale scope is not present in the resolved capell-app/frontend.');
