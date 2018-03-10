<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\Core\Models\SiteDomain;
use Capell\HtmlCache\Actions\ClearCachedUrlAction;
use Capell\HtmlCache\Actions\RetireCachedUrlAction;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Support\Cache\PageCache;
use Capell\HtmlCache\Support\Cache\StatelessPaginationRequest;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

require_once dirname(__DIR__) . '/Support/CachedModelUrlsTestSupport.php';

uses(HtmlCacheTestCase::class);

function htmlCacheRetirementIndex(string $url, ?int $siteId, ?int $domainId): CachedModelUrl
{
    return CachedModelUrl::query()->create([
        'url' => $url,
        'url_hash' => CachedModelUrl::hashUrl($url),
        'path' => '/page',
        'site_id' => $siteId,
        'site_domain_id' => $domainId,
        'cacheable_type' => SiteDomain::class,
        'cacheable_id' => CachedModelUrl::query()->count() + 1,
        'cached_at' => now(),
        'last_seen_at' => now(),
    ]);
}

/** @return list<string> */
function htmlCacheRetirementArtefacts(Request $request): array
{
    $prefix = 'https.example.test/page' . StatelessPaginationRequest::cacheKeySuffix($request);
    $paths = [$prefix . '.html', $prefix . PageCache::ERROR_EXTENSION];

    return [...$paths, ...array_map(static fn (string $path): string => $path . PageCache::FRAGMENT_METADATA_EXTENSION, $paths)];
}

beforeEach(function (): void {
    Storage::fake('page_cache');
    config()->set('capell-html-cache.enabled', true);
    config()->set('capell-html-cache.write_enabled', true);
    config()->set('capell-html-cache.request_coalescing.enabled', false);
    config()->set('capell-html-cache.bypass.paths', []);
    config()->set('capell-html-cache.stateless_pagination.enabled', true);
    config()->set('capell-html-cache.stateless_pagination.params', ['page']);
});

it('retires middleware artefacts and indexes only for accepted response statuses', function (string $blocker, int $status): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    bindHtmlCacheFrontendContext(Page::factory()->recycle($domain->site)->withTranslations()->create());
    $request = Request::create('https://example.test/page');
    $request->attributes->set(HtmlCacheMiddleware::BYPASS_CACHE_READ_ATTRIBUTE, true);
    app()->instance('request', $request);
    $index = htmlCacheRetirementIndex($request->fullUrl(), $domain->site_id, $domain->id);
    $artefacts = htmlCacheRetirementArtefacts($request);
    foreach ($artefacts as $artefact) {
        Storage::disk('page_cache')->put($artefact, 'previous public output');
    }
    if ($blocker === 'configured') {
        config()->set('capell-html-cache.bypass.paths', ['/page']);
    }
    $headers = [
        'Content-Type' => $blocker === 'non-html' ? 'application/json' : 'text/html',
        'Cache-Control' => $blocker === 'private' ? 'private' : 'public',
        'Location' => '/new-location',
    ];

    $response = resolve(HtmlCacheMiddleware::class)->handle($request, fn (): Response => response('fresh origin output', $status, $headers));

    $retired = ($status >= 200 && $status < 300) || in_array($status, [301, 308], true);
    expect($response->getStatusCode())->toBe($status);
    foreach ($artefacts as $artefact) {
        expect(Storage::disk('page_cache')->exists($artefact))->toBe(! $retired);
    }
    expect($index->fresh() instanceof CachedModelUrl)->toBe(! $retired);
})->with(['private', 'non-html', 'configured'])->with([200, 204, 301, 308, 302, 303, 307, 404, 500]);

it('preserves canonical artefacts and indexes for unsupported query retirement and clearing', function (string $entry): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    bindHtmlCacheFrontendContext(Page::factory()->recycle($domain->site)->withTranslations()->create());
    $canonical = Request::create('https://example.test/page');
    $index = htmlCacheRetirementIndex($canonical->fullUrl(), $domain->site_id, $domain->id);
    $artefacts = htmlCacheRetirementArtefacts($canonical);
    foreach ($artefacts as $artefact) {
        Storage::disk('page_cache')->put($artefact, 'canonical public output');
    }
    $request = Request::create('https://example.test/page?preview=1');
    app()->instance('request', $request);
    $response = response('private preview', 200, ['Content-Type' => 'text/html', 'Cache-Control' => 'private']);

    if ($entry === 'action') {
        expect(retireDeclaredHtmlCacheOriginResponse($request, $response))->toBeNull();
    } elseif ($entry === 'middleware') {
        expect(resolve(HtmlCacheMiddleware::class)->handle($request, fn (): Response => $response)->getContent())->toBe('private preview');
    } else {
        expect(ClearCachedUrlAction::run($request->fullUrl(), $domain))->toBeFalse();
    }

    foreach ($artefacts as $artefact) {
        expect(Storage::disk('page_cache')->get($artefact))->toBe('canonical public output');
    }
    expect($index->fresh())->not->toBeNull();
})->with(['action', 'middleware', 'clear']);

it('retires the exact stored query and fragment variant without removing canonical files', function (bool $fragment): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    bindHtmlCacheFrontendContext(Page::factory()->recycle($domain->site)->withTranslations()->create());
    $canonical = Request::create('https://example.test/page');
    $request = Request::create('https://example.test/page?page=2');
    if ($fragment) {
        $request->headers->set(StatelessPaginationRequest::FRAGMENT_HEADER, 'articles');
    }
    app()->instance('request', $request);
    $pageCache = resolve(PageCache::class);
    expect($pageCache->cache($this->beginCacheRender($request), response('variant public output', 200, ['Content-Type' => 'text/html', 'Cache-Control' => 'public'])))->toBeTrue();
    $artefacts = htmlCacheRetirementArtefacts($request);
    expect(Storage::disk('page_cache')->exists($artefacts[0]))->toBeTrue();
    foreach ($artefacts as $artefact) {
        Storage::disk('page_cache')->put($artefact, 'variant public output');
    }
    foreach (htmlCacheRetirementArtefacts($canonical) as $artefact) {
        Storage::disk('page_cache')->put($artefact, 'canonical public output');
    }
    $canonicalIndex = htmlCacheRetirementIndex($canonical->fullUrl(), $domain->site_id, $domain->id);
    $variantIndex = htmlCacheRetirementIndex($request->fullUrl(), $domain->site_id, $domain->id);

    expect(retireDeclaredHtmlCacheOriginResponse($request, response('private variant', 200, ['Content-Type' => 'text/html', 'Cache-Control' => 'private'])))->not->toBeNull();

    foreach ($artefacts as $artefact) {
        expect(Storage::disk('page_cache')->exists($artefact))->toBeFalse();
    }
    foreach (htmlCacheRetirementArtefacts($canonical) as $artefact) {
        expect(Storage::disk('page_cache')->get($artefact))->toBe('canonical public output');
    }
    expect($canonicalIndex->fresh())->not->toBeNull()
        ->and($variantIndex->fresh())->toBeNull();
})->with(['query' => false, 'query and fragment' => true]);

it('matches nullable retirement ownership without deleting neighbouring site and domain indexes', function (bool $nullSite, bool $nullDomain): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $neighbour = SiteDomain::factory()->create();
    $url = 'https://example.test/page';
    $siteId = $nullSite ? null : $domain->site_id;
    $domainId = $nullDomain ? null : $domain->id;
    $target = htmlCacheRetirementIndex($url, $siteId, $domainId);
    $neighbours = [
        htmlCacheRetirementIndex($url, $neighbour->site_id, $domainId),
        htmlCacheRetirementIndex($url, $siteId, $neighbour->id),
        htmlCacheRetirementIndex($url, $neighbour->site_id, $neighbour->id),
    ];

    resolve(RetireCachedUrlAction::class)->evict($url, 'https.example.test/page.html', 'https.example.test/page.404.html', $siteId, $domainId, suppressInlineEdgePurge: true);

    expect($target->fresh())->toBeNull();
    foreach ($neighbours as $index) {
        expect($index->fresh())->not->toBeNull();
    }
})->with(['null site' => true, 'owned site' => false])->with(['null domain' => true, 'owned domain' => false]);

it('retires middleware cache keys for unregistered hosts without creating a domain', function (): void {
    $request = Request::create('https://unregistered.example.test/page');
    $request->attributes->set(HtmlCacheMiddleware::BYPASS_CACHE_READ_ATTRIBUTE, true);
    app()->instance('request', $request);
    $index = htmlCacheRetirementIndex($request->fullUrl(), null, null);
    $artefacts = [
        'https.unregistered.example.test/page.html',
        'https.unregistered.example.test/page.404.html',
        'https.unregistered.example.test/page.html.fragments.json',
        'https.unregistered.example.test/page.404.html.fragments.json',
    ];
    foreach ($artefacts as $artefact) {
        Storage::disk('page_cache')->put($artefact, 'previous public output');
    }

    $response = resolve(HtmlCacheMiddleware::class)->handle($request, fn (): Response => response('private output', 200, ['Content-Type' => 'text/html', 'Cache-Control' => 'private']));

    expect($response->getContent())->toBe('private output')
        ->and($index->fresh())->toBeNull()
        ->and(SiteDomain::query()->where('domain', $request->getHost())->exists())->toBeFalse();
    foreach ($artefacts as $artefact) {
        expect(Storage::disk('page_cache')->exists($artefact))->toBeFalse();
    }
});

it('never reads or overwrites canonical files through unsupported low-level query cache keys', function (string $entry, string $query): void {
    $canonical = Request::create('https://example.test/page');
    $artefacts = htmlCacheRetirementArtefacts($canonical);
    foreach ($artefacts as $artefact) {
        Storage::disk('page_cache')->put($artefact, 'canonical public output');
    }
    $request = Request::create('https://example.test/page?' . $query);
    app()->instance('request', $request);
    $pageCache = resolve(PageCache::class);

    if ($entry === 'read') {
        expect($pageCache->getCachePage($request))->toBeFalse()
            ->and($pageCache->getCacheErrorPage($request))->toBeFalse()
            ->and($pageCache->getCacheFragmentData($request))->toBeNull();
    } else {
        foreach ([200, 404] as $status) {
            expect($pageCache->cache($this->beginCacheRender($request), response('unsupported variant output', $status, ['Content-Type' => 'text/html', 'Cache-Control' => 'public'])))->toBeFalse();
        }
    }

    foreach ($artefacts as $artefact) {
        expect(Storage::disk('page_cache')->get($artefact))->toBe('canonical public output');
    }
})->with(['read', 'write'])->with(['preview=1', 'page=2&preview=1', 'page%5B%5D=2']);
