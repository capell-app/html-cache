<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\Core\Models\SiteDomain;
use Capell\Frontend\Data\RenderHookFragmentCacheData;
use Capell\HtmlCache\Actions\ClearCachedUrlAction;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Models\StaleCachedUrl;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Capell\HtmlCache\Support\Cache\PageCache;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

require_once dirname(__DIR__) . '/Support/CachedModelUrlsTestSupport.php';

uses(HtmlCacheTestCase::class);

function baselineCompatibilityRequest(string $url, ?string $fragment): Request
{
    $request = Request::create($url);
    if ($fragment !== null) {
        $request->headers->set('X-Fragment', $fragment);
    }
    app()->instance('request', $request);

    return $request;
}

function baselineCompatibilityCachePath(Request $request, bool $error = false): string
{
    // Frozen origin/main derivation for allow-listed scalar queries: the query
    // and fragment marker share ONE hash. Never derive upgrade fixtures from HEAD.
    $parts = [];
    $params = $request->query->all();
    if ($params !== []) {
        ksort($params);
        $parts[] = http_build_query($params);
    }
    if ($request->headers->has('X-Fragment')) {
        $parts[] = 'fragment=' . (string) $request->headers->get('X-Fragment');
    }
    $suffix = $parts === [] ? '' : '~' . mb_substr(hash('xxh128', implode('|', $parts)), 0, 16);
    $segments = $request->segments();
    $filename = array_pop($segments);
    $filename = in_array($filename, [null, '', 'index'], true) ? 'pc__index__pc' : $filename;

    return $request->getScheme() . '.' . $request->getHost() . '/'
        . implode('/', [...$segments, $filename . $suffix . ($error ? '.404.html' : '.html')]);
}

/** @return array<string, string> */
function baselineCompatibilityInventory(): array
{
    $files = [];
    foreach (Storage::disk('page_cache')->allFiles() as $file) {
        $contents = Storage::disk('page_cache')->get($file);
        assert(is_string($contents));
        $files[$file] = $contents;
    }
    ksort($files);

    return $files;
}

beforeEach(function (): void {
    Storage::fake('page_cache');
    Queue::fake();
    config()->set('capell-html-cache.enabled', true);
    config()->set('capell-html-cache.write_enabled', true);
    config()->set('capell-html-cache.minify_html', false);
    config()->set('capell-html-cache.request_coalescing.enabled', false);
    config()->set('capell-html-cache.origin_swr.enabled', false);
    config()->set('capell-html-cache.bypass', ['paths' => [], 'cookies' => [], 'headers' => []]);
    config()->set('capell-html-cache.stateless_pagination.enabled', true);
    config()->set('capell-html-cache.stateless_pagination.params', ['page', 'theme_tag']);
});

it('uses recorded legacy ownership exactly and removes orphan fragment sidecars', function (): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $targetUrl = 'https://example.test/page?page=2';
    $targetFiles = [];
    foreach ([$targetUrl, 'https://example.test/page?page=3'] as $url) {
        $request = baselineCompatibilityRequest($url, 'recorded fragment');
        $cachePath = baselineCompatibilityCachePath($request);
        $errorPath = baselineCompatibilityCachePath($request, error: true);
        StaleCachedUrl::query()->create([
            'url' => $url, 'url_hash' => CachedModelUrl::hashUrl($url),
            'path' => '/page', 'stale_key' => hash('sha256', $url),
            'site_id' => $domain->site_id, 'site_domain_id' => $domain->id,
            'cache_path' => $cachePath, 'error_cache_path' => $errorPath,
        ]);
        foreach ([$cachePath, $errorPath] as $file) {
            foreach ([$file, $file . PageCache::FRAGMENT_METADATA_EXTENSION] as $artefact) {
                Storage::disk('page_cache')->put($artefact, 'recorded:' . $artefact);
                if ($url === $targetUrl) {
                    $targetFiles[] = $artefact;
                }
            }
        }
    }
    foreach (['https://example.test/page?page=3', 'https://example.test/other-site/page?page=3', 'https://other.test/page?page=3'] as $url) {
        $file = baselineCompatibilityCachePath(baselineCompatibilityRequest($url, 'unrecorded fragment'));
        $sidecar = $file . PageCache::FRAGMENT_METADATA_EXTENSION;
        Storage::disk('page_cache')->put($sidecar, 'orphan:' . $sidecar);
        if ($url === 'https://example.test/page?page=3') {
            $targetFiles[] = $sidecar;
        }
    }
    $before = baselineCompatibilityInventory();
    $beforeRows = StaleCachedUrl::query()->orderBy('id')->get()->map->getRawOriginal()->all();

    expect(ClearCachedUrlAction::run($targetUrl, $domain))->toBeTrue()
        ->and(baselineCompatibilityInventory())->toBe(array_diff_key($before, array_fill_keys($targetFiles, true)))
        ->and(StaleCachedUrl::query()->orderBy('id')->get()->map->getRawOriginal()->all())->toBe($beforeRows);
});

it('preserves origin/main full-response and fragment stored names', function (string $path, string $query, ?string $fragment): void {
    $request = baselineCompatibilityRequest('https://example.test' . $path . $query, $fragment);
    $paths = resolve(HtmlCachePathResolver::class);
    $cache = resolve(PageCache::class);
    foreach ([false, true] as $error) {
        $expected = baselineCompatibilityCachePath($request, $error);
        expect($paths->pathForRequestUrl($request, error: $error))->toBe($expected);
        expect($cache->cache($this->beginCacheRender($request), response('baseline-compatible output', $error ? 404 : 200, ['Content-Type' => 'text/html'])))->toBeTrue();
        expect(Storage::disk('page_cache')->get($expected))->toBe('baseline-compatible output');
    }
})->with(['/', '/index', '/page', '/section/index', '/space%20name', '/caf%C3%A9'])
    ->with(['', '?page=2', '?page=3', '?theme_tag=templates&page=2', '?page=2&theme_tag=templates'])
    ->with(['full response' => null, 'empty fragment' => '', 'articles', 'navigation', 'custom|fragment=value']);

it('serves baseline-produced HTML and 404 files after upgrade', function (string $path, ?string $fragment, bool $error): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    bindHtmlCacheFrontendContext(Page::factory()->recycle($domain->site)->withTranslations()->create());
    $request = baselineCompatibilityRequest('https://example.test' . $path, $fragment);
    $file = baselineCompatibilityCachePath($request, $error);
    $body = 'legacy:' . $file;
    Storage::disk('page_cache')->put($file, $body);
    Storage::disk('page_cache')->put($file . PageCache::FRAGMENT_METADATA_EXTENSION, json_encode(new RenderHookFragmentCacheData($body, [])->metadata(), JSON_THROW_ON_ERROR));

    $response = resolve(HtmlCacheMiddleware::class)->handle($request, static fn (): never => throw new RuntimeException('An existing baseline cache file must not render origin.'));
    expect($response->getContent())->toBe($body)
        ->and($response->getStatusCode())->toBe($error ? 404 : 200);
})->with(['/page', '/page?page=2', '/index?page=3'])
    ->with(['full response' => null, 'articles', 'navigation', 'custom|fragment=value'])
    ->with([false, true]);

it('conservatively evicts unattributable legacy artefacts while preserving attributable neighbour bytes', function (): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $targetFiles = [];
    foreach (['https://example.test/page', 'https://example.test/page?page=2', 'https://example.test/page?page=3', 'https://example.test/other?page=2', 'https://neighbour.test/page?page=2'] as $url) {
        if (str_starts_with($url, 'https://example.test/')) {
            CachedModelUrl::query()->create([
                'url' => $url, 'url_hash' => CachedModelUrl::hashUrl($url),
                'path' => Request::create($url)->getPathInfo(),
                'site_id' => $domain->site_id, 'site_domain_id' => $domain->id,
                'cacheable_type' => SiteDomain::class, 'cacheable_id' => $domain->id,
            ]);
        }
        foreach ([null, 'articles', 'navigation', 'custom|fragment=value'] as $fragment) {
            $request = baselineCompatibilityRequest($url, $fragment);
            foreach ([false, true] as $error) {
                $file = baselineCompatibilityCachePath($request, $error);
                $body = 'legacy:' . $file;
                $metadataFile = $file . PageCache::FRAGMENT_METADATA_EXTENSION;
                Storage::disk('page_cache')->put($file, $body);
                Storage::disk('page_cache')->put($metadataFile, json_encode(new RenderHookFragmentCacheData($body, [])->metadata(), JSON_THROW_ON_ERROR));
                // Combined legacy hashes cannot distinguish fragments from full
                // query responses; every unattributable hash here must be evicted.
                if ($url === 'https://example.test/page?page=2'
                    || ($fragment !== null && Request::create($url)->getHost() === 'example.test'
                        && Request::create($url)->getPathInfo() === '/page')) {
                    $targetFiles[] = $file;
                    $targetFiles[] = $metadataFile;
                }
            }
        }
    }
    $before = baselineCompatibilityInventory();
    $beforeRows = CachedModelUrl::query()->orderBy('id')->get()->map->getRawOriginal()->all();
    expect($before)->toHaveCount(80);
    expect(ClearCachedUrlAction::run('https://example.test/page?page=2', $domain))->toBeTrue();
    expect(baselineCompatibilityInventory())->toBe(array_diff_key($before, array_fill_keys($targetFiles, true)))
        ->and(CachedModelUrl::query()->orderBy('id')->get()->map->getRawOriginal()->all())->toBe(array_values(array_filter(
            $beforeRows,
            static fn (array $row): bool => $row['url'] !== 'https://example.test/page?page=2',
        )));
});

it('evicts untracked legacy query responses but preserves tracked and canonical bytes', function (bool $tracked): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $neighbourUrl = 'https://example.test/page?page=3';
    if ($tracked) {
        CachedModelUrl::query()->create([
            'url' => $neighbourUrl, 'url_hash' => CachedModelUrl::hashUrl($neighbourUrl),
            'path' => '/page', 'site_id' => $domain->site_id, 'site_domain_id' => $domain->id,
            'cacheable_type' => SiteDomain::class, 'cacheable_id' => $domain->id,
        ]);
    }
    $targetFiles = [];
    $neighbourFiles = [];
    $canonicalFiles = [];
    foreach (['https://example.test/page?page=2', $neighbourUrl, 'https://example.test/page', 'https://example.test/other?page=3', 'https://example.test/other-site/page?page=3', 'https://neighbour.test/page?page=3'] as $url) {
        foreach ([false, true] as $error) {
            $file = baselineCompatibilityCachePath(baselineCompatibilityRequest($url, null), $error);
            foreach ([$file, $file . PageCache::FRAGMENT_METADATA_EXTENSION] as $artefact) {
                Storage::disk('page_cache')->put($artefact, 'legacy full response:' . $artefact);
                if ($url === 'https://example.test/page?page=2') {
                    $targetFiles[] = $artefact;
                } elseif ($url === $neighbourUrl) {
                    $neighbourFiles[] = $artefact;
                } elseif ($url === 'https://example.test/page') {
                    $canonicalFiles[] = $artefact;
                }
            }
        }
    }
    $before = baselineCompatibilityInventory();
    $beforeRows = CachedModelUrl::query()->orderBy('id')->get()->map->getRawOriginal()->all();
    expect($before)->toHaveCount(24);
    expect(ClearCachedUrlAction::run('https://example.test/page?page=2', $domain))->toBeTrue();
    $after = baselineCompatibilityInventory();
    expect(array_intersect_key($after, array_fill_keys($canonicalFiles, true)))
        ->toBe(array_intersect_key($before, array_fill_keys($canonicalFiles, true)));
    if ($tracked) {
        expect(array_intersect_key($after, array_fill_keys($neighbourFiles, true)))
            ->toBe(array_intersect_key($before, array_fill_keys($neighbourFiles, true)));
    } else {
        // Untracked full responses share opaque legacy fragment names, so safe eviction costs only regeneration.
        expect(array_intersect_key($after, array_fill_keys($neighbourFiles, true)))->toBe([]);
        $targetFiles = [...$targetFiles, ...$neighbourFiles];
    }
    expect($after)->toBe(array_diff_key($before, array_fill_keys($targetFiles, true)))
        ->and(CachedModelUrl::query()->orderBy('id')->get()->map->getRawOriginal()->all())->toBe($beforeRows);
})->with(['untracked response' => false, 'tracked response' => true]);
