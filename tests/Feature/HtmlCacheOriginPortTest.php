<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\HtmlCache\Actions\BuildHtmlCacheEligibilityReportAction;
use Capell\HtmlCache\Actions\ClaimStaleCachedUrlAction;
use Capell\HtmlCache\Actions\ClearAllHtmlCacheAction;
use Capell\HtmlCache\Actions\ClearCachedUrlAction;
use Capell\HtmlCache\Actions\ClearCachedUrlsForSurrogateKeysAction;
use Capell\HtmlCache\Actions\MarkCachedUrlStaleAction;
use Capell\HtmlCache\Actions\RefreshCachedUrlAtomicallyAction;
use Capell\HtmlCache\Actions\WriteRefreshedHtmlCacheFileAction;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Models\StaleCachedUrl;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Capell\HtmlCache\Support\Cache\HtmlCachePublicationGuard;
use Capell\HtmlCache\Support\Cache\PageCache;
use Capell\HtmlCache\Support\StaticSite\StaticSiteExtensionRegistry;
use Capell\HtmlCache\Support\StaticSite\StaticSiteGenerator;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Capell\HtmlCache\Tests\Support\CacheOriginContexts;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\AssertableJsonString;

require_once __DIR__ . '/../Support/CacheOriginContexts.php';

uses(HtmlCacheTestCase::class);

beforeEach(function (): void {
    Storage::fake('page_cache');
    Queue::fake();
    config()->set('capell-html-cache.enabled', true);
    config()->set('capell-html-cache.write_enabled', true);
    config()->set('capell-html-cache.minify_html', false);
    config()->set('capell-html-cache.request_coalescing.enabled', false);
    config()->set('capell-html-cache.origin_swr.enabled', false);
});

it('retains literal legacy roots and files on implicit and explicit default ports', function (string $url, string $root): void {
    $request = Request::create($url);
    $domain = new SiteDomain(['scheme' => $request->getScheme(), 'domain' => 'example.test', 'port' => $request->getPort(), 'path' => null]);
    $paths = resolve(HtmlCachePathResolver::class);
    app()->instance('request', $request);

    expect($paths->rootForRequest($request))->toBe($root)
        ->and($paths->rootForRequest($request, $domain))->toBe($root)
        ->and($paths->pathForRequestUrl($url, $domain))->toBe($root . '/pc__index__pc.html')
        ->and($paths->pathForUrl('/form', $domain))->toBe($root . '/form.html')
        ->and($paths->pathForUrl('/missing', $domain, error: true))->toBe($root . '/missing.404.html')
        ->and($paths->directoryForSiteDomain($domain))->toBe($root)
        ->and(resolve(PageCache::class)->getCachePath())->toBe(Storage::disk('page_cache')->path($root));
})->with([
    ['http://example.test/', 'http.example.test'],
    ['http://example.test:80/', 'http.example.test'],
    ['https://example.test/', 'https.example.test'],
    ['https://example.test:443/', 'https.example.test'],
]);

it('isolates persisted HTML and snapshots between two serving ports', function (): void {
    foreach ([8080, 8081] as $port) {
        $request = Request::create('http://example.test:' . $port . '/form');
        app()->instance('request', $request);
        expect(resolve(PageCache::class)->cache($this->beginCacheRender($request), response('<form action="' . $request->getSchemeAndHttpHost() . '/submit"></form>', headers: ['Content-Type' => 'text/html'])))->toBeTrue();
    }

    foreach ([8080, 8081] as $port) {
        $request = Request::create('http://example.test:' . $port . '/form');
        app()->instance('request', $request);
        expect(resolve(PageCache::class)->getCachePage($request))->toBe('<form action="http://example.test:' . $port . '/submit"></form>');
    }

    expect(Storage::disk('page_cache')->allFiles())->toHaveCount(2);
});

it('uses the trusted effective origin and ignores untrusted forwarding', function (bool $trusted, string $forwardedPort, string $root): void {
    $previousProxies = Request::getTrustedProxies();
    /** @var int<0, 63> $previousHeaders */
    $previousHeaders = Request::getTrustedHeaderSet();
    Request::setTrustedProxies($trusted ? ['127.0.0.1'] : [], Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT);
    try {
        $request = Request::create('http://internal.test:9000/form', server: [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_HOST' => 'example.test',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_PORT' => $forwardedPort,
        ]);
        app()->instance('request', $request);
        app()->instance('request', $request);
        $paths = resolve(HtmlCachePathResolver::class);
        expect($paths->rootForRequest($request))->toBe($root)
            ->and($paths->pathForRequestUrl($request))->toBe($root . '/form.html')
            ->and(resolve(PageCache::class)->getCachePath())->toBe(Storage::disk('page_cache')->path($root));
    } finally {
        Request::setTrustedProxies($previousProxies, $previousHeaders);
    }
})->with([
    [true, '8443', '~port-8443/https.example.test'],
    [true, '443', 'https.example.test'],
    [false, '8443', '~port-9000/http.internal.test'],
]);

it('keeps stale records warmers readers and purgers on the same origin', function (string $scheme, int $port, string $hostHeader, string $root, string $warmer): void {
    $domain = SiteDomain::factory()->create(['scheme' => $scheme, 'domain' => 'example.test', 'port' => $port, 'path' => null]);
    $url = $scheme . '://example.test:' . $port . '/form';
    $paths = resolve(HtmlCachePathResolver::class);
    $seen = [];
    Route::get('/form', function (Request $request) use (&$seen): Response {
        $seen[] = [$request->headers->get('host'), $request->getScheme(), $request->getPort()];

        return response('<form action="' . $request->getSchemeAndHttpHost() . '/submit"></form>', headers: ['Content-Type' => 'text/html', 'Cache-Control' => 'public']);
    })->middleware(HtmlCacheMiddleware::class);
    MarkCachedUrlStaleAction::run($url);
    $stale = StaleCachedUrl::query()->where('url', $url)->sole();

    expect($stale->cache_path)->toBe($root . '/form.html')
        ->and($stale->error_cache_path)->toBe($root . '/form.404.html')
        ->and($paths->pathForUrl('/form', $domain))->toBe($root . '/form.html')
        ->and($paths->directoryForSiteDomain($domain))->toBe($root);

    if ($warmer === 'stale') {
        expect(ClaimStaleCachedUrlAction::run($stale))->toBeTrue();
        RefreshCachedUrlAtomicallyAction::run($stale->refresh());
    } else {
        config()->set('capell-html-cache.static_generation.internal_requests', true);
        app()->instance(StaticSiteExtensionRegistry::class, new StaticSiteExtensionRegistry);
        resolve(StaticSiteExtensionRegistry::class)->register('origin-port', static function (Site $site, SiteDomain $siteDomain, Closure $visit) use ($url): void {
            $visit($url);
        });
        new StaticSiteGenerator($domain->site)->process();
    }

    $effectivePort = in_array($port, [80, 443], true) ? ($scheme === 'http' ? 80 : 443) : $port;
    expect($seen)->toBe([[$hostHeader, $scheme, $effectivePort]]);
    $request = Request::create($url);
    app()->instance('request', $request);
    $html = '<form action="' . $scheme . '://' . $hostHeader . '/submit"></form>';
    expect(resolve(PageCache::class)->getCachePage($request))->toBe($html)
        ->and(Storage::disk('page_cache')->get($root . '/form.html'))->toBe($html);

    $neighbour = Request::create($scheme . '://example.test:8082/form');
    $neighbourPath = $paths->pathForRequestUrl($neighbour, $domain);
    Storage::disk('page_cache')->put($neighbourPath, 'neighbour');
    expect(ClearCachedUrlAction::run($url, $domain))->toBeTrue()
        ->and(Storage::disk('page_cache')->exists($root . '/form.html'))->toBeFalse()
        ->and(Storage::disk('page_cache')->get($neighbourPath))->toBe('neighbour');
    // Withdrawal counters deliberately fence all ports and query variants of a host/path.
    expect(HtmlCachePublicationGuard::urlKey($request))->toBe(HtmlCachePublicationGuard::urlKey($neighbour));
})->with([
    ['http', 80, 'example.test', 'http.example.test'],
    ['http', 443, 'example.test', 'http.example.test'],
    ['https', 80, 'example.test', 'https.example.test'],
    ['https', 443, 'example.test', 'https.example.test'],
    ['http', 8080, 'example.test:8080', '~port-8080/http.example.test'],
])->with(['stale', 'static']);

it('publishes an old stale row to the current port root without overwriting standard-port HTML', function (int $status, string $extension): void {
    SiteDomain::factory()->create(['scheme' => 'http', 'domain' => 'example.test', 'port' => 8080, 'path' => null]);
    $url = 'http://example.test:8080/form';
    MarkCachedUrlStaleAction::run($url);
    $stale = StaleCachedUrl::query()->where('url', $url)->sole();
    $stale->update(['cache_path' => 'http.example.test/form.html', 'error_cache_path' => 'http.example.test/form.404.html']);
    Storage::disk('page_cache')->put('http.example.test/form' . $extension, 'standard-port snapshot');
    Route::get('/form', static fn (): Response => response('fresh port snapshot', $status, ['Content-Type' => 'text/html', 'Cache-Control' => 'public']))->middleware(HtmlCacheMiddleware::class);
    expect(ClaimStaleCachedUrlAction::run($stale))->toBeTrue();
    RefreshCachedUrlAtomicallyAction::run($stale->refresh());

    expect(Storage::disk('page_cache')->get('http.example.test/form' . $extension))->toBe('standard-port snapshot')
        ->and(Storage::disk('page_cache')->get('~port-8080/http.example.test/form' . $extension))->toBe('fresh port snapshot');
})->with([[200, '.html'], [404, '.404.html']]);

it('diagnoses the target port independently of the ambient request', function (int $port, string $state, string $entry): void {
    Storage::disk('page_cache')->put('~port-8080/http.example.test/form.html', 'cached form');
    $ambient = Request::create('http://example.test:8081/form');
    app()->instance('request', $ambient);
    $url = 'http://example.test:' . $port . '/form';

    if ($entry === 'report') {
        expect(BuildHtmlCacheEligibilityReportAction::run(Request::create($url))->cacheState)->toBe($state);
    } else {
        expect(Artisan::call('capell:html-cache:diagnose', ['url' => $url, '--json' => true]))->toBe(0);
        new AssertableJsonString(Artisan::output())->assertPath('cacheState', $state);
    }

    expect(request())->toBe($ambient);
})->with([[8080, 'hit'], [8081, 'missing']])->with(['report', 'command']);

it('does not add a full clear to ordinary standard-port domain saves', function (?int $originalPort, ?int $savedPort): void {
    config()->set('capell-html-cache.invalidation.mode', 'scheduled');
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'port' => null, 'path' => null]);
    // Include legacy persisted explicit defaults before the saving observer normalises them.
    $domain->forceFill(['port' => $originalPort])->saveQuietly();
    $domain = SiteDomain::query()->whereKey($domain->getKey())->sole();
    Queue::fake();

    $domain->update(['port' => $savedPort]);

    ClearAllHtmlCacheAction::assertNotPushed();
    ClearCachedUrlsForSurrogateKeysAction::assertPushed();
})->with([[null, null], [443, 443], [null, 443]]);

it('retains the literal legacy root for every standard origin context', function (array|string|null $context, bool $trusted, string $root): void {
    $previousProxies = Request::getTrustedProxies();
    /** @var int<0, 63> $previousHeaders */
    $previousHeaders = Request::getTrustedHeaderSet();
    $appUrl = 'https://cache.example.test';
    config()->set('app.url', $appUrl);

    try {
        CacheOriginContexts::bind($context, $trusted);
        $request = app()->bound('request') ? resolve('request') : Request::create($appUrl);
        app()->instance('request', $request);
        $paths = resolve(HtmlCachePathResolver::class);
        expect($paths->nonStandardPortForRequest($request))->toBeNull()
            ->and($paths->rootForRequest($request))->toBe($root)
            ->and($paths->pathForRequestUrl($request))->toBe($root . '/pc__index__pc.html')
            ->and(resolve(PageCache::class)->getCachePath())->toBe(Storage::disk('page_cache')->path($root));
    } finally {
        Request::setTrustedProxies($previousProxies, $previousHeaders);
    }
})->with(array_map(static function (array $entry, string $context): array {
    $httpContexts = [
        'direct HTTP with Host', 'untrusted proxy bare Host', 'untrusted proxy explicit Host 443',
        'explicit HTTP 80', 'HTTP perceived on 443', 'hostless HTTP string 80',
        'hostless HTTP integer 80', 'hostless absent port',
    ];
    $root = $context === 'site domain HTTP without port' ? 'http.site.example.test'
        : ($context === 'site domain HTTPS without port' ? 'https.site.example.test'
            : (in_array($context, $httpContexts, true) ? 'http.cache.example.test' : 'https.cache.example.test'));

    return [...$entry, $root];
}, CacheOriginContexts::standard(), array_keys(CacheOriginContexts::standard())));

it('preserves the stored standard-port refresh path for statuses paths queries and domain aliases', function (string $url, string $storedPath, int $status): void {
    $request = Request::create($url);
    $stale = StaleCachedUrl::query()->create([
        'stale_key' => hash('sha256', $url), 'url' => $url, 'url_hash' => hash('sha256', $url),
        'path' => $request->getPathInfo(), 'cache_path' => $storedPath . '.html',
        'error_cache_path' => $storedPath . '.404.html', 'reason' => 'manual',
        'status' => StaleCachedUrl::STATUS_PROCESSING, 'claim_token' => 'refresh-claim',
    ]);
    $extension = $status === 404 ? '.404.html' : '.html';
    app()->instance('request', $request);
    $this->beginCacheRender($request);

    expect(WriteRefreshedHtmlCacheFileAction::run(response('fresh snapshot', $status), $stale, $request))->toBeTrue()
        ->and(Storage::disk('page_cache')->allFiles())->toBe([$storedPath . $extension])
        ->and(Storage::disk('page_cache')->get($storedPath . $extension))->toBe('fresh snapshot');
})->with([
    ['http://example.test/', 'http.example.test/pc__index__pc'],
    ['https://example.test:443/', 'https.example.test/pc__index__pc'],
    ['http://example.test:443/form', 'http.example.test/form'],
    ['https://example.test:80/form', 'https.example.test/form'],
    ['https://example.test/form', 'https.example.test/form'],
    ['https://example.test/form?page=2', 'https.example.test/form~stored-query'],
    ['https://alias.example.test/form', 'https.example.test/form'],
    ['https://example.test/prefix/index', 'https.example.test/prefix/index'],
])->with([200, 404]);

it('rebuilds the origin through the existing request path after domain surrogate clearing', function (): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'port' => null, 'path' => null]);
    $domain = SiteDomain::query()->whereKey($domain->getKey())->sole();

    $url = 'https://example.test/form';
    CachedModelUrl::query()->create([
        'url' => $url, 'url_hash' => CachedModelUrl::hashUrl($url), 'path' => '/form',
        'site_id' => $domain->site_id, 'site_domain_id' => $domain->getKey(),
        'language_id' => $domain->language_id, 'cacheable_type' => (new Page)->getMorphClass(),
        'cacheable_id' => 1, 'cached_at' => now(), 'last_seen_at' => now(),
    ]);
    Storage::disk('page_cache')->put('https.example.test/form.html', 'old snapshot');
    Queue::fake();
    $domain->update(['port' => 443]);
    ClearAllHtmlCacheAction::assertNotPushed();
    ClearCachedUrlsForSurrogateKeysAction::assertPushed();
    expect(ClearCachedUrlsForSurrogateKeysAction::run(['site-' . $domain->site_id]))->toBe(1)
        ->and(Storage::disk('page_cache')->exists('https.example.test/form.html'))->toBeFalse();

    Route::get('/form', static fn (): Response => response('fresh origin snapshot', headers: ['Content-Type' => 'text/html', 'Cache-Control' => 'public']))->middleware(HtmlCacheMiddleware::class);
    $this->get($url)->assertOk()->assertSee('fresh origin snapshot');
    $request = Request::create($url);
    app()->instance('request', $request);
    expect(resolve(PageCache::class)->getCachePage($request))->toBe('fresh origin snapshot');
});
