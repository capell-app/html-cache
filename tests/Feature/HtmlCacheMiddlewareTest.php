<?php

declare(strict_types=1);

use Capell\Core\Models\SiteDomain;
use Capell\Frontend\Actions\AssertPublicHtmlContainsNoAuthoringSurfaceAction;
use Capell\Frontend\Support\Routing\FrontendRouteMiddlewareRegistry;
use Capell\Frontend\Support\Security\PublicHtmlSafetyInspector;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Support\Cache\PageCache;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Cookie;

uses(HtmlCacheTestCase::class);

function htmlCacheMiddlewareFakeSafetyInspector(bool $containsAuthoringSurface): object
{
    return new class($containsAuthoringSurface)
    {
        public int $calls = 0;

        public function __construct(private readonly bool $containsAuthoringSurface) {}

        public function containsAuthoringSurface(string $content): bool
        {
            $this->calls++;

            return $this->containsAuthoringSurface;
        }
    };
}

beforeEach(function (): void {
    config()->set('capell-html-cache.enabled', true);
    config()->set('capell-html-cache.write_enabled', true);
    config()->set('capell-html-cache.cache_ttl', '3600');
});

it('bypasses cached html for requests with a session cookie by default', function (): void {
    Storage::fake('page_cache');
    config()->set('session.cookie', 'capell_session');

    $siteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => null,
    ]);
    $request = Request::create('https://example.test/about', Symfony\Component\HttpFoundation\Request::METHOD_GET);
    app()->instance('request', $request);
    resolve(PageCache::class)->cache($request, response('cached html', 200, ['Content-Type' => 'text/html']));

    $request->cookies->set('capell_session', 'session-value');
    $request->setUserResolver(fn (): User => User::factory()->create());

    $response = resolve(HtmlCacheMiddleware::class)->handle(
        $request,
        fn (): Response => response('fresh html', 200, ['Content-Type' => 'text/html']),
    );

    expect($response->getContent())->toBe('fresh html')
        ->and($response->headers->get('X-Frontend-Cache'))->toBeNull()
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store');
});

it('can serve cached html for requests with a session cookie when configured', function (): void {
    Storage::fake('page_cache');
    config()->set('session.cookie', 'capell_session');
    config()->set('capell-html-cache.cache_skip_authenticated', false);

    $siteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => null,
    ]);
    $request = Request::create('https://example.test/about', Symfony\Component\HttpFoundation\Request::METHOD_GET);
    app()->instance('request', $request);
    resolve(PageCache::class)->cache($request, response('cached html', 200, ['Content-Type' => 'text/html']));

    $request->cookies->set('capell_session', 'session-value');
    $request->setUserResolver(fn (): User => User::factory()->create());

    $response = resolve(HtmlCacheMiddleware::class)->handle(
        $request,
        fn (): Response => response('fresh html', 200, ['Content-Type' => 'text/html']),
    );

    expect($response->getContent())->toBe('cached html')
        ->and($response->headers->get('X-Frontend-Cache'))->toBe('HIT')
        ->and((string) $response->headers->get('Cache-Control'))->toContain('public');
});

it('bypasses cached html for authenticated requests without a session cookie by default', function (): void {
    Storage::fake('page_cache');

    $siteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => null,
    ]);
    $request = Request::create('https://example.test/about', Symfony\Component\HttpFoundation\Request::METHOD_GET);
    app()->instance('request', $request);
    resolve(PageCache::class)->cache($request, response('cached html', 200, ['Content-Type' => 'text/html']));

    $request->setUserResolver(fn (): User => User::factory()->create());

    $response = resolve(HtmlCacheMiddleware::class)->handle(
        $request,
        fn (): Response => response('fresh html', 200, ['Content-Type' => 'text/html']),
    );

    expect($response->getContent())->toBe('fresh html')
        ->and($response->headers->get('X-Frontend-Cache'))->toBeNull()
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store');
});

it('does not write cached html when the response contains authoring markers', function (): void {
    Storage::fake('page_cache');
    $siteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => null,
    ]);
    $request = Request::create('https://example.test/about', Symfony\Component\HttpFoundation\Request::METHOD_GET);
    app()->instance('request', $request);

    $response = resolve(HtmlCacheMiddleware::class)->handle(
        $request,
        fn (): Response => response('<div data-capell-editor="1"></div>', 200, ['Content-Type' => 'text/html']),
    );

    expect($response->headers->get('X-Frontend-Cache'))->toBe('BYPASS')
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(Storage::disk('page_cache')->allFiles())->toBe([]);
});

it('reuses matching public html safety inspection results before caching', function (): void {
    $content = '<main>safe public html</main>';
    $request = Request::create('https://example.test/about', Symfony\Component\HttpFoundation\Request::METHOD_GET);
    $request->attributes->set(AssertPublicHtmlContainsNoAuthoringSurfaceAction::SAFE_INSPECTION_PASSED_ATTRIBUTE, true);
    $request->attributes->set(AssertPublicHtmlContainsNoAuthoringSurfaceAction::SAFE_INSPECTION_HASH_ATTRIBUTE, hash('xxh128', $content));

    $inspector = htmlCacheMiddlewareFakeSafetyInspector(containsAuthoringSurface: true);

    app()->instance(PublicHtmlSafetyInspector::class, $inspector);

    $shouldCache = resolve(PageCache::class)->shouldCachePage(
        $request,
        response($content, 200, ['Content-Type' => 'text/html']),
    );

    expect($shouldCache)->toBeTrue()
        ->and($inspector->calls)->toBe(0);
});

it('reuses matching public html safety inspection results in middleware response handling', function (): void {
    Storage::fake('page_cache');

    $content = '<main>safe public html</main>';
    $siteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => null,
    ]);
    $request = Request::create('https://example.test/about', Symfony\Component\HttpFoundation\Request::METHOD_GET);
    $request->attributes->set(AssertPublicHtmlContainsNoAuthoringSurfaceAction::SAFE_INSPECTION_PASSED_ATTRIBUTE, true);
    $request->attributes->set(AssertPublicHtmlContainsNoAuthoringSurfaceAction::SAFE_INSPECTION_HASH_ATTRIBUTE, hash('xxh128', $content));
    app()->instance('request', $request);

    $inspector = htmlCacheMiddlewareFakeSafetyInspector(containsAuthoringSurface: true);

    app()->instance(PublicHtmlSafetyInspector::class, $inspector);

    $response = resolve(HtmlCacheMiddleware::class)->handle(
        $request,
        fn (): Response => response($content, 200, ['Content-Type' => 'text/html']),
    );

    expect($response->getContent())->toBe($content)
        ->and($response->headers->get('X-Frontend-Cache'))->toBe('MISS')
        ->and($inspector->calls)->toBe(0);
});

it('rescans public html when the remembered safety inspection hash does not match', function (): void {
    $content = '<main data-capell-editor="1">unsafe public html</main>';
    $request = Request::create('https://example.test/about', Symfony\Component\HttpFoundation\Request::METHOD_GET);
    $request->attributes->set(AssertPublicHtmlContainsNoAuthoringSurfaceAction::SAFE_INSPECTION_PASSED_ATTRIBUTE, true);
    $request->attributes->set(AssertPublicHtmlContainsNoAuthoringSurfaceAction::SAFE_INSPECTION_HASH_ATTRIBUTE, hash('xxh128', '<main>previous safe html</main>'));

    $inspector = htmlCacheMiddlewareFakeSafetyInspector(containsAuthoringSurface: true);

    app()->instance(PublicHtmlSafetyInspector::class, $inspector);

    $shouldCache = resolve(PageCache::class)->shouldCachePage(
        $request,
        response($content, 200, ['Content-Type' => 'text/html']),
    );

    expect($shouldCache)->toBeFalse()
        ->and($inspector->calls)->toBe(1);
});

it('rescans middleware public html when the remembered safety inspection hash does not match', function (): void {
    Storage::fake('page_cache');

    $content = '<main data-capell-editor="1">unsafe public html</main>';
    $siteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => null,
    ]);
    $request = Request::create('https://example.test/about', Symfony\Component\HttpFoundation\Request::METHOD_GET);
    $request->attributes->set(AssertPublicHtmlContainsNoAuthoringSurfaceAction::SAFE_INSPECTION_PASSED_ATTRIBUTE, true);
    $request->attributes->set(AssertPublicHtmlContainsNoAuthoringSurfaceAction::SAFE_INSPECTION_HASH_ATTRIBUTE, hash('xxh128', '<main>previous safe html</main>'));
    app()->instance('request', $request);

    $inspector = htmlCacheMiddlewareFakeSafetyInspector(containsAuthoringSurface: true);

    app()->instance(PublicHtmlSafetyInspector::class, $inspector);

    $response = resolve(HtmlCacheMiddleware::class)->handle(
        $request,
        fn (): Response => response($content, 200, ['Content-Type' => 'text/html']),
    );

    expect($response->headers->get('X-Frontend-Cache'))->toBe('BYPASS')
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(Storage::disk('page_cache')->allFiles())->toBe([])
        ->and($inspector->calls)->toBe(1);
});

it('returns cached 404 html with a 404 status code', function (): void {
    Storage::fake('page_cache');
    $siteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => null,
    ]);
    $request = Request::create('https://example.test/missing', Symfony\Component\HttpFoundation\Request::METHOD_GET);
    app()->instance('request', $request);
    resolve(PageCache::class)->cache($request, response('missing cached html', 404, ['Content-Type' => 'text/html']));

    $response = resolve(HtmlCacheMiddleware::class)->handle(
        $request,
        fn (): Response => response('fresh missing', 404, ['Content-Type' => 'text/html']),
    );

    expect($response->getStatusCode())->toBe(404)
        ->and($response->getContent())->toBe('missing cached html')
        ->and($response->headers->get('X-Frontend-Cache'))->toBe('HIT');
});

it('can bypass cache reads for internal stale refresh requests while still allowing writes', function (): void {
    Storage::fake('page_cache');
    $siteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => null,
    ]);
    $request = Request::create('https://example.test/missing', Symfony\Component\HttpFoundation\Request::METHOD_GET);
    $request->attributes->set(HtmlCacheMiddleware::BYPASS_CACHE_READ_ATTRIBUTE, true);

    app()->instance('request', $request);
    resolve(PageCache::class)->cache($request, response('old cached html', 200, ['Content-Type' => 'text/html']));

    $response = resolve(HtmlCacheMiddleware::class)->handle(
        $request,
        fn (): Response => response('fresh missing html', 404, ['Content-Type' => 'text/html']),
    );

    expect($response->getStatusCode())->toBe(404)
        ->and($response->getContent())->toBe('fresh missing html')
        ->and($response->headers->get('X-Frontend-Cache'))->toBe('MISS')
        ->and(Storage::disk('page_cache')->exists('https.example.test/missing.404.html'))->toBeTrue();
});

it('strips configured cookies from anonymous cache hits', function (): void {
    Storage::fake('page_cache');
    config()->set('session.cookie', 'capell_session');
    $siteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => null,
    ]);
    $request = Request::create('https://example.test/about', Symfony\Component\HttpFoundation\Request::METHOD_GET);
    app()->instance('request', $request);
    resolve(PageCache::class)->cache($request, response('cached html', 200, ['Content-Type' => 'text/html']));

    $response = resolve(HtmlCacheMiddleware::class)->handle(
        $request,
        function (): Response {
            $response = response('fresh html', 200, ['Content-Type' => 'text/html']);
            $response->headers->setCookie(new Cookie('capell_session', 'new-session'));

            return $response;
        },
    );

    expect($response->getContent())->toBe('cached html')
        ->and($response->headers->getCookies())->toBe([]);
});

it('wraps web middleware before stripping cacheable response cookies', function (): void {
    $middleware = resolve(FrontendRouteMiddlewareRegistry::class)->all();

    expect(array_search('frontend.no_session_cookies_on_cache', $middleware, true))
        ->toBeLessThan(array_search('web', $middleware, true))
        ->and(array_search('frontend.cache', $middleware, true))
        ->toBeGreaterThan(array_search('web', $middleware, true));
});
