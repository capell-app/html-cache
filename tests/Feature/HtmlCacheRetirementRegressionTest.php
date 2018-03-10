<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\Core\Models\SiteDomain;
use Capell\HtmlCache\Actions\ClearCachedUrlAction;
use Capell\HtmlCache\Actions\ClearCachedUrlsForModelAction;
use Capell\HtmlCache\Actions\MarkAllCachedUrlsStaleAction;
use Capell\HtmlCache\Actions\MarkCachedUrlsForModelStaleAction;
use Capell\HtmlCache\Actions\MarkCachedUrlsForSiteStaleAction;
use Capell\HtmlCache\Actions\MarkCachedUrlStaleAction;
use Capell\HtmlCache\Actions\NotifyClearCachedPagesAction;
use Capell\HtmlCache\Actions\PurgeEdgeCacheAction;
use Capell\HtmlCache\Actions\RecordCachedModelUrlsAction;
use Capell\HtmlCache\Actions\RefreshCachedUrlAtomicallyAction;
use Capell\HtmlCache\Exceptions\StaleCachedUrlNotApplicableException;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Models\StaleCachedUrl;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Capell\HtmlCache\Support\Cache\PageCache;
use Capell\HtmlCache\Support\Cache\StatelessPaginationRequest;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

require_once dirname(__DIR__) . '/Support/CachedModelUrlsTestSupport.php';

uses(HtmlCacheTestCase::class);

function retirementRegressionIndex(string $url, ?int $siteId, ?int $domainId): CachedModelUrl
{
    return CachedModelUrl::query()->create([
        'url' => $url,
        'url_hash' => CachedModelUrl::hashUrl($url),
        'path' => '/index',
        'site_id' => $siteId,
        'site_domain_id' => $domainId,
        'cacheable_type' => SiteDomain::class,
        'cacheable_id' => CachedModelUrl::query()->count() + 1,
        'cached_at' => now(),
        'last_seen_at' => now(),
    ]);
}

function retirementRegressionPurgeRegistrations(Closure $operation): int
{
    return DB::transaction(function () use ($operation): int {
        $manager = app('db.transactions');
        assert($manager instanceof DatabaseTransactionsManager);
        $transaction = $manager->getPendingTransactions()->last();
        assert($transaction instanceof DatabaseTransactionRecord);
        $operation();

        return count($transaction->getCallbacks());
    });
}

beforeEach(function (): void {
    Storage::fake('page_cache');
    Queue::fake();
    config()->set('capell-html-cache.enabled', true);
    config()->set('capell-html-cache.write_enabled', true);
    config()->set('capell-html-cache.minify_html', false);
    config()->set('capell-html-cache.request_coalescing.enabled', false);
    config()->set('capell-html-cache.bypass', ['paths' => [], 'cookies' => [], 'headers' => []]);
    config()->set('capell-html-cache.stateless_pagination.enabled', true);
    config()->set('capell-html-cache.stateless_pagination.params', ['page']);
});

it('removes the actual PageCache artefacts through the shared retirement and clear key', function (string $path, string $entry): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => str_starts_with($path, '/docs/') ? '/docs' : null]);
    bindHtmlCacheFrontendContext(Page::factory()->recycle($domain->site)->withTranslations()->create());
    $request = Request::create('https://example.test' . $path);
    if ($entry === 'fragment retirement') {
        $request->headers->set(StatelessPaginationRequest::FRAGMENT_HEADER, 'articles');
    }
    app()->instance('request', $request);
    $pageCache = resolve(PageCache::class);
    foreach ([200, 404] as $status) {
        expect($pageCache->cache($this->beginCacheRender($request), response('stored public output', $status, ['Content-Type' => 'text/html', 'Cache-Control' => 'public'])))->toBeTrue();
    }
    expect($pageCache->getCachePage($request))->toBe('stored public output')
        ->and($pageCache->getCacheErrorPage($request))->toBe('stored public output');
    $artefacts = Storage::disk('page_cache')->allFiles();
    expect($artefacts)->toHaveCount(2);
    foreach ($artefacts as $artefact) {
        Storage::disk('page_cache')->put($artefact . PageCache::FRAGMENT_METADATA_EXTENSION, 'old fragment metadata');
    }
    $index = retirementRegressionIndex($request->fullUrl(), $domain->site_id, $domain->id);

    if ($entry === 'clear') {
        expect(ClearCachedUrlAction::run($request->fullUrl(), $domain))->toBeTrue();
    } else {
        expect(retireDeclaredHtmlCacheOriginResponse($request, response('private output', 200, ['Content-Type' => 'text/html', 'Cache-Control' => 'private'])))->not->toBeNull();
    }

    expect(Storage::disk('page_cache')->allFiles())->toBe([])
        ->and($pageCache->getCachePage($request))->toBeFalse()
        ->and($pageCache->getCacheErrorPage($request))->toBeFalse()
        ->and($index->fresh())->toBeNull();
})->with(['/index', '/index?page=2', '/space%20name', '/caf%C3%A9', '/docs/index', '/docs/', '/docs/index?page=2'])
    ->with(['retirement', 'fragment retirement', 'clear']);

it('clears only null-exact site and domain ownership for string and row inputs', function (bool $nullSite, bool $nullDomain, string $entry): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $neighbour = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'neighbour.test', 'path' => null]);
    $url = 'https://example.test/index';
    $siteId = $nullSite ? null : $domain->site_id;
    $domainId = $nullDomain ? null : $domain->id;
    $target = retirementRegressionIndex($url, $siteId, $domainId);
    $sameScope = retirementRegressionIndex($url, $siteId, $domainId);
    $neighbours = [
        retirementRegressionIndex($url, $neighbour->site_id, $domainId),
        retirementRegressionIndex($url, $siteId, $neighbour->id),
        retirementRegressionIndex($url, $neighbour->site_id, $neighbour->id),
    ];
    $paths = resolve(HtmlCachePathResolver::class);
    foreach ([false, true] as $error) {
        $targetPath = $paths->pathForRequestUrl($url, $domain, error: $error);
        $neighbourPath = $paths->pathForRequestUrl($url, $neighbour, error: $error);
        Storage::disk('page_cache')->put($targetPath, 'target output');
        Storage::disk('page_cache')->put($targetPath . PageCache::FRAGMENT_METADATA_EXTENSION, 'target fragments');
        Storage::disk('page_cache')->put($neighbourPath, 'neighbour output');
        Storage::disk('page_cache')->put($neighbourPath . PageCache::FRAGMENT_METADATA_EXTENSION, 'neighbour fragments');
    }
    // The supplied domain is the exact scope, including explicitly null ownership.
    $scope = (new SiteDomain)->forceFill([...$domain->getAttributes(), 'id' => $domainId, 'site_id' => $siteId]);

    expect(ClearCachedUrlAction::run($entry === 'row' ? $target : $url, $scope))->toBeTrue()
        ->and($target->fresh())->toBeNull()
        ->and($sameScope->fresh())->toBeNull();
    foreach ($neighbours as $index) {
        expect($index->fresh())->not->toBeNull();
    }
    foreach ([false, true] as $error) {
        $targetPath = $paths->pathForRequestUrl($url, $domain, error: $error);
        $neighbourPath = $paths->pathForRequestUrl($url, $neighbour, error: $error);
        expect(Storage::disk('page_cache')->exists($targetPath))->toBeFalse()
            ->and(Storage::disk('page_cache')->exists($targetPath . PageCache::FRAGMENT_METADATA_EXTENSION))->toBeFalse()
            ->and(Storage::disk('page_cache')->get($neighbourPath))->toBe('neighbour output')
            ->and(Storage::disk('page_cache')->get($neighbourPath . PageCache::FRAGMENT_METADATA_EXTENSION))->toBe('neighbour fragments');
    }
})->with([true, false])->with([true, false])->with(['string', 'row']);

it('rejects a supplied domain outside the selected row ownership before deleting anything', function (): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $neighbour = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'neighbour.test', 'path' => null]);
    $url = 'https://example.test/index';
    $target = retirementRegressionIndex($url, $domain->site_id, $domain->id);
    $foreign = retirementRegressionIndex($url, $neighbour->site_id, $neighbour->id);
    $paths = resolve(HtmlCachePathResolver::class);
    $targetPath = $paths->pathForRequestUrl($url, $domain);
    $foreignPath = $paths->pathForRequestUrl($url, $neighbour);
    Storage::disk('page_cache')->put($targetPath, 'target output');
    Storage::disk('page_cache')->put($foreignPath, 'neighbour output');

    expect(fn (): bool => ClearCachedUrlAction::run($target, $neighbour))->toThrow(InvalidArgumentException::class)
        ->and($target->fresh())->not->toBeNull()
        ->and($foreign->fresh())->not->toBeNull()
        ->and(Storage::disk('page_cache')->get($targetPath))->toBe('target output')
        ->and(Storage::disk('page_cache')->get($foreignPath))->toBe('neighbour output');
    PurgeEdgeCacheAction::assertNotPushed();
});

it('uses the row domain or anonymous URL key instead of a supplied neighbouring hostname', function (bool $anonymous): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $neighbour = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'neighbour.test', 'path' => null]);
    $url = 'https://example.test/index';
    $target = retirementRegressionIndex($url, $anonymous ? null : $domain->site_id, $anonymous ? null : $domain->id);
    $foreign = retirementRegressionIndex($url, $neighbour->site_id, $neighbour->id);
    $paths = resolve(HtmlCachePathResolver::class);
    $targetPath = $paths->pathForRequestUrl($url, $domain);
    $foreignPath = $paths->pathForRequestUrl($url, $neighbour);
    Storage::disk('page_cache')->put($targetPath, 'target output');
    Storage::disk('page_cache')->put($foreignPath, 'neighbour output');
    $supplied = (new SiteDomain)->forceFill([...$neighbour->getAttributes(), 'site_id' => $target->site_id, 'id' => $target->site_domain_id]);

    expect(ClearCachedUrlAction::run($target, $supplied))->toBeTrue()
        ->and($target->fresh())->toBeNull()
        ->and($foreign->fresh())->not->toBeNull()
        ->and(Storage::disk('page_cache')->exists($targetPath))->toBeFalse()
        ->and(Storage::disk('page_cache')->get($foreignPath))->toBe('neighbour output');
})->with(['owned domain' => false, 'anonymous scope' => true]);

it('retains historical ownership when a model clear follows its cached URL caller', function (bool $notification): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'historical.test', 'path' => null]);
    $neighbour = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $url = 'https://example.test/index';
    $target = retirementRegressionIndex($url, $domain->site_id, $domain->id);
    $foreign = retirementRegressionIndex($url, $neighbour->site_id, $neighbour->id);
    $target->update(['cacheable_type' => $domain->getMorphClass(), 'cacheable_id' => $domain->id]);
    $foreign->update(['cacheable_type' => $neighbour->getMorphClass(), 'cacheable_id' => $neighbour->id]);
    $paths = resolve(HtmlCachePathResolver::class);
    $targetPath = $paths->pathForRequestUrl($url, $domain);
    $foreignPath = $paths->pathForRequestUrl($url, $neighbour);
    Storage::disk('page_cache')->put($targetPath, 'historical output');
    Storage::disk('page_cache')->put($foreignPath, 'neighbour output');

    if ($notification) {
        config()->set('capell-admin.auto_clear_cache', true);
        NotifyClearCachedPagesAction::run(collect([$domain]));
    } else {
        expect(ClearCachedUrlsForModelAction::run($target->cacheable_type, $target->cacheable_id))->toBe(1);
    }
    expect($target->fresh())->toBeNull()
        ->and($foreign->fresh())->not->toBeNull()
        ->and(Storage::disk('page_cache')->exists($targetPath))->toBeFalse()
        ->and(Storage::disk('page_cache')->get($foreignPath))->toBe('neighbour output');
})->with(['model clear' => false, 'automatic notification' => true]);

it('forgets the actual stored aliases and encoded keys without a second filename derivation', function (string $path): void {
    $request = Request::create('https://example.test' . $path);
    app()->instance('request', $request);
    $cache = resolve(PageCache::class);
    foreach ([200, 404] as $status) {
        expect($cache->cache($this->beginCacheRender($request), response('stored output', $status, ['Content-Type' => 'text/html'])))->toBeTrue();
    }
    $files = Storage::disk('page_cache')->allFiles();
    expect($files)->toHaveCount(2);
    foreach ($files as $file) {
        Storage::disk('page_cache')->put($file . PageCache::FRAGMENT_METADATA_EXTENSION, 'old fragments');
    }

    expect($cache->forget($request->getRequestUri()))->toBeTrue()
        ->and(Storage::disk('page_cache')->allFiles())->toBe([]);
})->with(['/index', '/space%20name', '/docs/', '/index?page=2']);

it('rejects directory clears that could escape the current domain scope', function (string $path): void {
    $request = Request::create('https://example.test/page');
    app()->instance('request', $request);
    Storage::disk('page_cache')->put('https.example.test/page.html', 'target output');
    Storage::disk('page_cache')->put('https.neighbour.test/page.html', 'neighbour output');

    expect(fn (): bool => resolve(PageCache::class)->clear($path))->toThrow(InvalidArgumentException::class)
        ->and(Storage::disk('page_cache')->get('https.example.test/page.html'))->toBe('target output')
        ->and(Storage::disk('page_cache')->get('https.neighbour.test/page.html'))->toBe('neighbour output');
})->with(['../https.neighbour.test', '%2e%2e/https.neighbour.test', '%252e%252e/https.neighbour.test']);

it('prunes rendered URL dependencies only within the exact current ownership', function (): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $neighbour = SiteDomain::factory()->create();
    $url = 'https://example.test/index';
    $obsolete = retirementRegressionIndex($url, $domain->site_id, $domain->id);
    $foreign = retirementRegressionIndex($url, $neighbour->site_id, $neighbour->id);
    $page = Page::factory()->recycle($domain->site)->withTranslations()->create();

    RecordCachedModelUrlsAction::run($url, [$page->getMorphClass() => [$page->id]]);

    expect($obsolete->fresh())->toBeNull()
        ->and($foreign->fresh())->not->toBeNull()
        ->and(CachedModelUrl::query()->where('cacheable_type', $page->getMorphClass())->where('cacheable_id', $page->id)->where('site_id', $domain->site_id)->where('site_domain_id', $domain->id)->exists())->toBeTrue();
});

it('preserves the anonymous default HTML for visitor-specific retirement and middleware bypasses', function (string $variant, string $entry): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    bindHtmlCacheFrontendContext(Page::factory()->recycle($domain->site)->withTranslations()->create());
    $default = Request::create('https://example.test/pricing');
    $default->headers->remove('Accept-Language');
    app()->instance('request', $default);
    $pageCache = resolve(PageCache::class);
    expect($pageCache->cache($this->beginCacheRender($default), response('anonymous default HTML', 200, ['Content-Type' => 'text/html', 'Cache-Control' => 'public'])))->toBeTrue();
    $index = retirementRegressionIndex($default->fullUrl(), $domain->site_id, $domain->id);
    $request = Request::create($default->fullUrl());
    if ($variant === 'currency cookie') {
        config()->set('capell-html-cache.bypass.cookies', ['currency_*']);
        $request->headers->set('Cookie', 'currency_bucket=gbp');
    } elseif ($variant === 'locale header') {
        config()->set('capell-html-cache.bypass.headers', ['Accept-Language']);
        $request->headers->set('Accept-Language', 'fr');
    } elseif ($variant === 'authorisation') {
        $request->headers->set('Authorization', 'Bearer visitor');
    } elseif ($variant === 'session') {
        $request->cookies->set(config()->string('session.cookie'), 'visitor');
    } elseif ($variant === 'livewire') {
        $request->headers->set('X-Livewire', 'true');
    } elseif ($variant === 'inertia') {
        $request->headers->set('X-Inertia', 'true');
    } else {
        $request->setMethod('POST');
    }
    app()->instance('request', $request);
    $private = response('visitor-specific output', 200, ['Content-Type' => 'text/html', 'Cache-Control' => 'private, no-store']);
    if ($entry === 'action') {
        expect(retireDeclaredHtmlCacheOriginResponse($request, $private))->toBeNull();
    } else {
        expect(resolve(HtmlCacheMiddleware::class)->handle($request, fn (): Response => $private)->getContent())->toBe('visitor-specific output');
    }
    expect($index->fresh())->not->toBeNull()
        ->and($pageCache->getCachePage($default))->toBe('anonymous default HTML');
    app()->instance('request', $default);
    $served = resolve(HtmlCacheMiddleware::class)->handle($default, fn (): Response => response('unexpected origin render'));
    expect($served->getContent())->toBe('anonymous default HTML')
        ->and($served->headers->get('X-Frontend-Cache'))->toBe('HIT');
})->with(['currency cookie', 'locale header', 'authorisation', 'session', 'livewire', 'inertia', 'post'])->with(['action', 'middleware']);

it('validates refresh queries before missing-domain eviction', function (bool $registered): void {
    Route::get('/index', fn (): Response => response('preview output', 200, ['Content-Type' => 'text/html', 'Cache-Control' => 'private']))->middleware(HtmlCacheMiddleware::class);
    if ($registered) {
        SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    }
    $canonical = 'https://example.test/index';
    $request = Request::create($canonical);
    app()->instance('request', $request);
    $pageCache = resolve(PageCache::class);
    expect($pageCache->cache($this->beginCacheRender($request), response('anonymous default HTML', 200, ['Content-Type' => 'text/html'])))->toBeTrue();
    $files = Storage::disk('page_cache')->allFiles();
    $index = retirementRegressionIndex($canonical, null, null);
    $url = $canonical . '?preview=1';
    $stale = StaleCachedUrl::query()->create([
        'url' => $url, 'url_hash' => CachedModelUrl::hashUrl($url), 'path' => '/index',
        'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl($url), null, null, '/index'),
        'cache_path' => $files[0], 'error_cache_path' => $files[0],
        'status' => StaleCachedUrl::STATUS_PROCESSING, 'claim_token' => 'current',
    ]);
    $registrations = retirementRegressionPurgeRegistrations(function () use ($stale): void {
        expect(fn () => RefreshCachedUrlAtomicallyAction::run($stale))->toThrow(RuntimeException::class, 'Unsupported query parameters');
    });
    expect($pageCache->getCachePage($request))->toBe('anonymous default HTML')
        ->and($index->fresh())->not->toBeNull()
        ->and($registrations)->toBe(0);
})->with(['missing domain' => false, 'registered domain' => true]);

it('propagates refresh purge suppression through middleware retirement', function (bool $suppress, bool $pathRule): void {
    Queue::fake();
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    bindHtmlCacheFrontendContext(Page::factory()->recycle($domain->site)->withTranslations()->create());
    $url = 'https://example.test/purge-suppression';
    $index = retirementRegressionIndex($url, $domain->site_id, $domain->id);
    $request = Request::create($url);
    app()->instance('request', $request);
    expect(resolve(PageCache::class)->cache($this->beginCacheRender($request), response('old public HTML', 200, ['Content-Type' => 'text/html'])))->toBeTrue();
    $paths = resolve(HtmlCachePathResolver::class);
    $stale = StaleCachedUrl::query()->create([
        'url' => $url, 'url_hash' => CachedModelUrl::hashUrl($url), 'path' => '/index',
        'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl($url), $domain->site_id, $domain->id, '/index'),
        'site_id' => $domain->site_id, 'site_domain_id' => $domain->id,
        'cache_path' => $paths->pathForRequestUrl($url, $domain), 'error_cache_path' => $paths->pathForRequestUrl($url, $domain, error: true),
        'status' => StaleCachedUrl::STATUS_PROCESSING, 'claim_token' => 'current',
    ]);
    Route::get('/purge-suppression', fn (): Response => response('fresh private output', 200, ['Content-Type' => 'text/html', 'Cache-Control' => 'private']))->middleware(HtmlCacheMiddleware::class);
    if ($pathRule) {
        config()->set('capell-html-cache.bypass.paths', ['/purge-suppression']);
    }
    $registrations = retirementRegressionPurgeRegistrations(function () use ($stale, $suppress): void {
        expect(fn () => RefreshCachedUrlAtomicallyAction::run($stale, $suppress))->toThrow(StaleCachedUrlNotApplicableException::class);
    });
    expect(Storage::disk('page_cache')->allFiles())->toBe([])
        ->and($index->fresh())->toBeNull()
        ->and($registrations)->toBe($suppress ? 0 : 2);
    if ($suppress) {
        PurgeEdgeCacheAction::assertNotPushed();
    } else {
        PurgeEdgeCacheAction::assertPushed();
    }
})->with([true, false])->with([true, false]);

it('removes historical stored filenames alongside current keys', function (string $path, string $entry): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $url = 'https://example.test' . $path;
    $index = retirementRegressionIndex($url, $domain->site_id, $domain->id);
    $index->update(['path' => $path]);
    $paths = resolve(HtmlCachePathResolver::class);
    $historical = 'https.example.test' . $path;
    $artefacts = [];
    foreach ([false, true] as $error) {
        foreach ([$historical . ($error ? '.404.html' : '.html'), $paths->pathForRequestUrl($url, $domain, error: $error)] as $file) {
            $artefacts[] = $file;
            $artefacts[] = $file . PageCache::FRAGMENT_METADATA_EXTENSION;
        }
    }
    foreach ($artefacts as $file) {
        Storage::disk('page_cache')->put($file, 'old output');
    }
    if ($entry === 'string clear' || $entry === 'row clear') {
        expect(ClearCachedUrlAction::run($entry === 'row clear' ? $index : $url, $domain))->toBeTrue();
    } elseif ($entry === 'request retirement') {
        expect(retireDeclaredHtmlCacheOriginResponse(Request::create($url), response('private', 200, ['Content-Type' => 'text/html', 'Cache-Control' => 'private'])))->not->toBeNull();
    } else {
        $stale = StaleCachedUrl::query()->create([
            'url' => $url, 'url_hash' => CachedModelUrl::hashUrl($url), 'path' => $path,
            'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl($url), $domain->site_id, $domain->id, $path),
            'site_id' => $domain->site_id, 'site_domain_id' => $domain->id,
            'cache_path' => $historical . '.html', 'error_cache_path' => $historical . '.404.html',
            'status' => StaleCachedUrl::STATUS_PROCESSING, 'claim_token' => 'current',
        ]);
        if ($entry === 'obsolete domain') {
            $domain->delete();
            RefreshCachedUrlAtomicallyAction::run($stale);
        } else {
            expect(retireDeclaredHtmlCacheOriginResponse(Request::create($url), response('private', 200, ['Content-Type' => 'text/html', 'Cache-Control' => 'private']), $stale))->not->toBeNull();
        }
    }
    foreach ($artefacts as $file) {
        expect(Storage::disk('page_cache')->exists($file))->toBeFalse();
    }
    expect($index->fresh())->toBeNull();
})->with(['/index', '/space%20name', '/caf%C3%A9', '/section%2Fpage'])->with(['string clear', 'row clear', 'request retirement', 'queued retirement', 'obsolete domain']);

it('clears fragment HTML error files and sidecars before serving fresh fragments', function (string $path, string $entry): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    bindHtmlCacheFrontendContext(Page::factory()->recycle($domain->site)->withTranslations()->create());
    $url = 'https://example.test' . $path;
    $index = retirementRegressionIndex($url, $domain->site_id, $domain->id);
    $index->update(['path' => Request::create($url)->getPathInfo()]);
    app()->instance('request', Request::create($url));
    $cache = resolve(PageCache::class);
    foreach ([null, 'articles', 'navigation'] as $fragment) {
        $request = Request::create($url);
        if ($fragment !== null) {
            $request->headers->set(StatelessPaginationRequest::FRAGMENT_HEADER, $fragment);
        }
        app()->instance('request', $request);
        foreach ([200, 404] as $status) {
            expect($cache->cache($this->beginCacheRender($request), response('old fragment HTML', $status, ['Content-Type' => 'text/html'])))->toBeTrue();
        }
    }
    $files = Storage::disk('page_cache')->allFiles();
    expect($files)->toHaveCount(6);
    foreach ($files as $file) {
        Storage::disk('page_cache')->put($file . PageCache::FRAGMENT_METADATA_EXTENSION, 'old metadata');
    }
    $neighbours = ['https.neighbour.test/pc__index__pc~0123456789abcdef.html', 'https.example.test/other~0123456789abcdef.404.html'];
    foreach ($neighbours as $file) {
        Storage::disk('page_cache')->put($file, 'neighbour');
    }

    expect(ClearCachedUrlAction::run($entry === 'row' ? $index : $url, $domain))->toBeTrue();
    foreach ($files as $file) {
        expect(Storage::disk('page_cache')->exists($file))->toBeFalse($file)
            ->and(Storage::disk('page_cache')->exists($file . PageCache::FRAGMENT_METADATA_EXTENSION))->toBeFalse();
    }
    foreach ($neighbours as $file) {
        expect(Storage::disk('page_cache')->get($file))->toBe('neighbour');
    }
    $request = Request::create($url);
    $request->headers->set(StatelessPaginationRequest::FRAGMENT_HEADER, 'articles');
    app()->instance('request', $request);
    expect($cache->getCachePage($request))->toBeFalse()
        ->and($cache->getCacheErrorPage($request))->toBeFalse();
    $response = resolve(HtmlCacheMiddleware::class)->handle($request, fn (): Response => response('fresh fragment HTML', 200, ['Content-Type' => 'text/html']));
    expect($response->getContent())->toBe('fresh fragment HTML')
        ->and($cache->getCachePage($request))->toBe('fresh fragment HTML');
})->with(['/index', '/index?page=2', '/.well-known'])->with(['string', 'row']);

it('serves baseline JSON responses when a rejected URL has no safe cache key', function (bool $bypass, string $entry): void {
    SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $request = Request::create('https://example.test/version..2');
    app()->instance('request', $request);
    if ($bypass) {
        config()->set('capell-html-cache.bypass.paths', ['/version..2']);
    }
    $origin = response()->json(['version' => '2']);
    if ($entry === 'action') {
        expect(retireDeclaredHtmlCacheOriginResponse($request, $origin))->toBeNull();
        $response = $origin;
    } else {
        $response = resolve(HtmlCacheMiddleware::class)->handle($request, fn (): Symfony\Component\HttpFoundation\Response => $origin);
    }
    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('{"version":"2"}')
        ->and(Storage::disk('page_cache')->allFiles())->toBe([]);
    PurgeEdgeCacheAction::assertNotPushed();
})->with([true, false])->with(['action', 'middleware']);

it('skips keyless URLs across clear and stale queue callers', function (string $entry): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $url = 'https://example.test/version..2';
    $index = retirementRegressionIndex($url, $domain->site_id, $domain->id);
    $index->update(['path' => '/version..2']);
    $result = match ($entry) {
        'string clear' => ClearCachedUrlAction::run($url, $domain),
        'row clear' => ClearCachedUrlAction::run($index),
        'mark string' => MarkCachedUrlStaleAction::run($url),
        'mark row' => MarkCachedUrlStaleAction::run($index),
        'mark all' => MarkAllCachedUrlsStaleAction::run(),
        'mark model' => MarkCachedUrlsForModelStaleAction::run($index->cacheable_type, $index->cacheable_id),
        'mark site' => MarkCachedUrlsForSiteStaleAction::run($domain->site_id),
        default => throw new InvalidArgumentException('Unknown cache invalidation caller.'),
    };
    expect($result)->toBe(in_array($entry, ['string clear', 'row clear'], true) ? false : 0)
        ->and($index->fresh())->not->toBeNull()
        ->and(StaleCachedUrl::query()->count())->toBe(0);
    PurgeEdgeCacheAction::assertNotPushed();
})->with(['string clear', 'row clear', 'mark string', 'mark row', 'mark all', 'mark model', 'mark site']);

it('rejects stored files outside the row domain and symlinked ancestors', function (bool $symlinked): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $url = 'https://example.test/index';
    $index = retirementRegressionIndex($url, $domain->site_id, $domain->id);
    $disk = Storage::disk('page_cache');
    $paths = resolve(HtmlCachePathResolver::class);
    foreach ([false, true] as $error) {
        $disk->put($paths->pathForRequestUrl($url, $domain, error: $error), 'target');
    }
    $foreignBase = 'https.neighbour.test/old-index';
    foreach (['.html', '.404.html', '.html.fragments.json', '.404.html.fragments.json'] as $extension) {
        $disk->put($foreignBase . $extension, 'foreign output');
    }
    $storedBase = $foreignBase;
    if ($symlinked) {
        expect(symlink($disk->path('https.neighbour.test'), $disk->path('https.example.test/linked')))->toBeTrue();
        $storedBase = 'https.example.test/linked/old-index';
    }
    $stale = StaleCachedUrl::query()->create([
        'url' => $url, 'url_hash' => CachedModelUrl::hashUrl($url), 'path' => '/index',
        'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl($url), $domain->site_id, $domain->id, '/index'),
        'site_id' => $domain->site_id, 'site_domain_id' => $domain->id,
        'cache_path' => $storedBase . '.html', 'error_cache_path' => $storedBase . '.404.html',
        'status' => StaleCachedUrl::STATUS_PROCESSING, 'claim_token' => 'current',
    ]);

    expect(retireDeclaredHtmlCacheOriginResponse(Request::create($url), response('private', 200, ['Content-Type' => 'text/html', 'Cache-Control' => 'private']), $stale))->not->toBeNull();
    foreach (['.html', '.404.html', '.html.fragments.json', '.404.html.fragments.json'] as $extension) {
        expect($disk->get($foreignBase . $extension))->toBe('foreign output');
    }
    foreach ([false, true] as $error) {
        expect($disk->exists($paths->pathForRequestUrl($url, $domain, error: $error)))->toBeFalse();
    }
    expect($index->fresh())->toBeNull();
})->with(['foreign domain' => false, 'symlinked directory' => true]);
