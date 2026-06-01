<?php

declare(strict_types=1);

use Capell\Core\Models\SiteDomain;
use Capell\Frontend\Actions\AssertPublicHtmlContainsNoAuthoringSurfaceAction;
use Capell\Frontend\Contracts\CacheBypassResolver;
use Capell\Frontend\Support\Routing\FrontendRouteMiddlewareRegistry;
use Capell\Frontend\Support\Security\PublicHtmlSafetyInspector;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Models\StaleCachedUrl;
use Capell\HtmlCache\Support\Cache\PageCache;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Cookie;

uses(HtmlCacheTestCase::class);

/**
 * @return object{calls: int}
 */
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

    capell_expect($response->getContent())->toBe('fresh html')
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

    capell_expect($response->getContent())->toBe('cached html')
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

    capell_expect($response->getContent())->toBe('fresh html')
        ->and($response->headers->get('X-Frontend-Cache'))->toBeNull()
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store');
});

it('bypasses cached html for access gated protected requests', function (): void {
    Storage::fake('page_cache');

    $siteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => null,
    ]);
    $request = Request::create('https://example.test/about', Symfony\Component\HttpFoundation\Request::METHOD_GET);
    $request->attributes->set('access_gate.protected', true);

    app()->instance('request', $request);
    resolve(PageCache::class)->cache($request, response('cached html', 200, ['Content-Type' => 'text/html']));

    $response = resolve(HtmlCacheMiddleware::class)->handle(
        $request,
        fn (): Response => response('protected html', 200, ['Content-Type' => 'text/html']),
    );

    capell_expect($response->getContent())->toBe('protected html')
        ->and($response->headers->get('X-Frontend-Cache'))->toBeNull()
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store');
});

it('bypasses cached html for access gate browser token requests even when authenticated cache reads are enabled', function (): void {
    Storage::fake('page_cache');
    config()->set('capell-html-cache.cache_skip_authenticated', false);

    $siteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => null,
    ]);
    $request = Request::create('https://example.test/about', Symfony\Component\HttpFoundation\Request::METHOD_GET);
    $request->cookies->set('capell_access_gate_browser_token', 'token-value');

    app()->instance('request', $request);
    resolve(PageCache::class)->cache($request, response('cached html', 200, ['Content-Type' => 'text/html']));

    $response = resolve(HtmlCacheMiddleware::class)->handle(
        $request,
        fn (): Response => response('token html', 200, ['Content-Type' => 'text/html']),
    );

    capell_expect($response->getContent())->toBe('token html')
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

    capell_expect($response->headers->get('X-Frontend-Cache'))->toBe('BYPASS')
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(Storage::disk('page_cache')->allFiles())->toBe([]);
});

it('does not fail public responses when a cache write fails', function (): void {
    $siteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => null,
    ]);
    $request = Request::create('https://example.test/about', Symfony\Component\HttpFoundation\Request::METHOD_GET);

    app()->instance('request', $request);
    resolve(PageCache::class)->setCachePath('/dev/null');

    $response = resolve(HtmlCacheMiddleware::class)->handle(
        $request,
        fn (): Response => response('fresh html', 200, ['Content-Type' => 'text/html']),
    );

    capell_expect($response->getContent())->toBe('fresh html')
        ->and($response->headers->get('X-Frontend-Cache'))->toBe('MISS')
        ->and($request->attributes->get(HtmlCacheMiddleware::CACHE_WRITE_SUCCEEDED_ATTRIBUTE))->toBeFalse()
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store');
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

    capell_expect($shouldCache)->toBeTrue();
    capell_expect($inspector->calls)->toBe(0);
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

    capell_expect($response->getContent())->toBe($content);
    capell_expect($response->headers->get('X-Frontend-Cache'))->toBe('MISS');
    capell_expect($inspector->calls)->toBe(0);
});

it('rejects cache eligibility for unsafe request and response states', function (): void {
    $pageCache = resolve(PageCache::class);
    $htmlResponse = response('<main>Public</main>', 200, ['Content-Type' => 'text/html']);

    app()->instance(CacheBypassResolver::class, new readonly class implements CacheBypassResolver
    {
        public function shouldBypass(): bool
        {
            return true;
        }
    });

    expect($pageCache->shouldCachePage(Request::create('https://example.test/about'), $htmlResponse))->toBeFalse();

    app()->forgetInstance(CacheBypassResolver::class);

    $withQuery = Request::create('https://example.test/about?preview=1');
    $postRequest = Request::create('https://example.test/about', Symfony\Component\HttpFoundation\Request::METHOD_POST);
    $inertiaRequest = Request::create('https://example.test/about');
    $inertiaRequest->headers->set('X-Inertia-Version', 'asset-version');

    $livewireRequest = Request::create('https://example.test/about');
    $livewireRequest->headers->set('x-livewire', 'true');

    $sessionRequest = Request::create('https://example.test/about');
    $sessionRequest->setLaravelSession(session()->driver());

    session()->put('_old_input', ['email' => 'ben@example.test']);

    expect($pageCache->shouldCachePage(Request::create('https://example.test/about?without_html_cache=1'), $htmlResponse))->toBeFalse()
        ->and($pageCache->shouldCachePage($withQuery, $htmlResponse))->toBeFalse()
        ->and($pageCache->shouldCachePage($postRequest, $htmlResponse))->toBeFalse()
        ->and($pageCache->shouldCachePage($inertiaRequest, $htmlResponse))->toBeFalse()
        ->and($pageCache->shouldCachePage($sessionRequest, $htmlResponse))->toBeFalse()
        ->and($pageCache->shouldCachePage(Request::create('https://example.test/about'), response('error', 500, ['Content-Type' => 'text/html'])))->toBeFalse()
        ->and($pageCache->shouldCachePage(Request::create('https://example.test/about'), response()->json(['ok' => true])))->toBeFalse()
        ->and($pageCache->shouldCachePage($livewireRequest, $htmlResponse))->toBeFalse();
});

it('writes and forgets error cache files using the public page cache API', function (): void {
    Storage::fake('page_cache');

    $request = Request::create('https://example.test/');
    $pageCache = resolve(PageCache::class);

    $pageCache->cache($request, response('not found', 404, ['Content-Type' => 'text/html']));

    expect($pageCache->getCacheErrorPage($request))->toBe('not found')
        ->and($pageCache->forget('pc__index__pc'))->toBeTrue()
        ->and($pageCache->getCacheErrorPage($request))->toBeFalse();
});

it('atomically replaces cache files only for the current stale cache claim', function (): void {
    Storage::fake('page_cache');

    $pageCache = resolve(PageCache::class);
    $validRequest = Request::create('https://example.test/about');
    $validStaleUrl = StaleCachedUrl::query()->create([
        'url' => 'https://example.test/about',
        'url_hash' => 'about-hash',
        'path' => '/about',
        'stale_key' => 'about-hash:about',
        'cache_path' => 'about.html',
        'error_cache_path' => 'about.404.html',
        'reason' => 'test',
        'status' => StaleCachedUrl::STATUS_PROCESSING,
        'claim_token' => 'valid-claim',
    ]);
    $validRequest->attributes->set(HtmlCacheMiddleware::STALE_CACHE_ID_ATTRIBUTE, $validStaleUrl->getKey());
    $validRequest->attributes->set(HtmlCacheMiddleware::STALE_CACHE_CLAIM_TOKEN_ATTRIBUTE, 'valid-claim');

    $pageCache->cache($validRequest, response('fresh about', 200, ['Content-Type' => 'text/html']));

    $invalidRequest = Request::create('https://example.test/contact');
    $invalidStaleUrl = StaleCachedUrl::query()->create([
        'url' => 'https://example.test/contact',
        'url_hash' => 'contact-hash',
        'path' => '/contact',
        'stale_key' => 'contact-hash:contact',
        'cache_path' => 'contact.html',
        'error_cache_path' => 'contact.404.html',
        'reason' => 'test',
        'status' => StaleCachedUrl::STATUS_PENDING,
        'claim_token' => 'old-claim',
    ]);
    $invalidRequest->attributes->set(HtmlCacheMiddleware::STALE_CACHE_ID_ATTRIBUTE, $invalidStaleUrl->getKey());
    $invalidRequest->attributes->set(HtmlCacheMiddleware::STALE_CACHE_CLAIM_TOKEN_ATTRIBUTE, 'new-claim');

    $pageCache->cache($invalidRequest, response('fresh contact', 200, ['Content-Type' => 'text/html']));

    expect(file_get_contents($pageCache->getCachePath('about.html')))->toBe('fresh about')
        ->and(file_exists($pageCache->getCachePath('contact.html')))->toBeFalse()
        ->and(glob($pageCache->getCachePath('contact.html.tmp.*')) ?: [])->toBe([]);
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

    capell_expect($shouldCache)->toBeFalse();
    capell_expect($inspector->calls)->toBe(1);
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

    capell_expect($response->headers->get('X-Frontend-Cache'))->toBe('BYPASS');
    capell_expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');
    capell_expect(Storage::disk('page_cache')->allFiles())->toBe([]);
    capell_expect($inspector->calls)->toBe(1);
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

    capell_expect($response->getStatusCode())->toBe(404)
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

    capell_expect($response->getStatusCode())->toBe(404)
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

    capell_expect($response->getContent())->toBe('cached html')
        ->and($response->headers->getCookies())->toBe([]);
});

it('wraps web middleware before stripping cacheable response cookies', function (): void {
    $middleware = resolve(FrontendRouteMiddlewareRegistry::class)->all();

    $noSessionCookiesPosition = array_search('frontend.no_session_cookies_on_cache', $middleware, true);
    $frontendCachePosition = array_search('frontend.cache', $middleware, true);
    $webPosition = array_search('web', $middleware, true);

    expect($noSessionCookiesPosition)->toBeInt();
    expect($frontendCachePosition)->toBeInt();
    expect($webPosition)->toBeInt();

    assert(is_int($noSessionCookiesPosition));
    assert(is_int($frontendCachePosition));
    assert(is_int($webPosition));

    capell_expect($noSessionCookiesPosition)
        ->toBeLessThan($webPosition)
        ->and($frontendCachePosition)
        ->toBeGreaterThan($webPosition);
});
