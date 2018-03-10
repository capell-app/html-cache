<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Frontend\Contracts\CacheBypassResolver;
use Capell\Frontend\Data\RenderHookContributionData;
use Capell\Frontend\Data\RenderHookFragmentCacheData;
use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Support\Render\RenderHookFragmentRegistry;
use Capell\Frontend\Support\Render\RenderHookRegistry;
use Capell\FrontendAuthoring\Actions\ClearAffectedCachedUrlsAction;
use Capell\HtmlCache\Actions\ClaimStaleCachedUrlAction;
use Capell\HtmlCache\Actions\ClearCachedPageUrlsAction;
use Capell\HtmlCache\Actions\ClearCachedUrlAction;
use Capell\HtmlCache\Actions\ClearCachedUrlsForModelAction;
use Capell\HtmlCache\Actions\ClearCachedUrlsForSurrogateKeysAction;
use Capell\HtmlCache\Actions\DeleteCachedUrlArtefactsAction;
use Capell\HtmlCache\Actions\MarkAllCachedUrlsStaleAction;
use Capell\HtmlCache\Actions\MarkCachedUrlsForModelStaleAction;
use Capell\HtmlCache\Actions\MarkCachedUrlsForSiteStaleAction;
use Capell\HtmlCache\Actions\MarkCachedUrlStaleAction;
use Capell\HtmlCache\Actions\ProcessStaleHtmlCacheAction;
use Capell\HtmlCache\Actions\PurgeEdgeCacheAction;
use Capell\HtmlCache\Actions\RefreshCachedUrlAtomicallyAction;
use Capell\HtmlCache\Actions\ResolveCachedUrlsForModelAction;
use Capell\HtmlCache\Actions\RetireCachedUrlAction;
use Capell\HtmlCache\Exceptions\StaleCachedUrlNotApplicableException;
use Capell\HtmlCache\Health\HtmlCacheHealthCheck;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Jobs\RefreshOriginStaleCachedUrlJob;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Models\StaleCachedUrl;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Capell\HtmlCache\Support\Cache\HtmlCachePublicationGuard;
use Capell\HtmlCache\Support\Cache\PageCache;
use Capell\HtmlCache\Support\Cache\StatelessPaginationRequest;
use Capell\HtmlCache\Support\StaticSite\StaticSiteExtensionRegistry;
use Capell\HtmlCache\Support\StaticSite\StaticSiteGenerator;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

require_once dirname(__DIR__) . '/Support/CachedModelUrlsTestSupport.php';

uses(HtmlCacheTestCase::class);

final class InvalidationMatrixOuterHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }
}

function invalidationMatrixSeed(string $url, SiteDomain $domain): CachedModelUrl
{
    $row = CachedModelUrl::query()->create([
        'url' => $url, 'url_hash' => CachedModelUrl::hashUrl($url),
        'path' => Request::create($url)->getPathInfo(),
        'site_id' => $domain->site_id, 'site_domain_id' => $domain->id,
        'cacheable_type' => $domain->getMorphClass(), 'cacheable_id' => $domain->id,
    ]);
    foreach ([null, 'articles'] as $fragment) {
        $request = invalidationMatrixRequest($url, $fragment);
        foreach ([false, true] as $error) {
            $file = resolve(HtmlCachePathResolver::class)->pathForRequestUrl($request, $domain, error: $error);
            Storage::disk('page_cache')->put($file, 'old:' . $file);
            Storage::disk('page_cache')->put($file . PageCache::FRAGMENT_METADATA_EXTENSION, json_encode(new RenderHookFragmentCacheData('old:' . $file, [])->metadata(), JSON_THROW_ON_ERROR));
        }
    }

    return $row;
}

/** @return array<string, string> */
function invalidationMatrixFiles(): array
{
    $files = [];
    foreach (Storage::disk('page_cache')->allFiles() as $file) {
        $contents = Storage::disk('page_cache')->get($file);
        if (! is_string($contents)) {
            throw new RuntimeException('Unable to read a matrix cache artefact: ' . $file);
        }
        $files[$file] = $contents;
    }
    ksort($files);

    return $files;
}

/** @return array<int, array<string, mixed>> */
function invalidationMatrixRows(): array
{
    return CachedModelUrl::query()->orderBy('id')->get()->mapWithKeys(
        static fn (CachedModelUrl $row): array => [$row->id => $row->getRawOriginal()],
    )->all();
}

function invalidationMatrixRequest(string $url, ?string $fragment): Request
{
    $request = Request::create($url);
    if ($fragment !== null) {
        $request->headers->set(StatelessPaginationRequest::FRAGMENT_HEADER, $fragment);
    }
    app()->instance('request', $request);

    return $request;
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
    config()->set('capell-html-cache.stateless_pagination.params', ['page']);
    config()->set('capell-html-cache.stateless_pagination.max_variants_per_path', 100);
});

// Compare the complete inventory, rather than selected filenames: missing targets,
// collateral eviction, lost historical keys and changed neighbour bytes all fail.
it('invalidates exactly the selected URL scope across the complete entry point matrix', function (string $entry, string $targetPath, ?string $fragment): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $neighbour = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'neighbour.test', 'path' => null]);
    $page = Page::factory()->recycle($domain->site)->withTranslations()->create();
    bindHtmlCacheFrontendContext($page);
    $page->pageUrls()->delete();
    $paths = resolve(HtmlCachePathResolver::class);
    $targetUrl = 'https://example.test' . $targetPath;
    $target = null;
    $targetFiles = [];
    $targetRequests = [];
    $seedOwners = [];
    $urlEntry = ! str_starts_with($entry, 'middleware ') && $entry !== 'retirement';
    $supported = $paths->hasSafeKey($targetUrl);
    $targetPathRequest = Request::create($targetUrl);
    $targetPathRequest->query->replace([]);
    $targetBase = $paths->pathForRequestUrl($targetPathRequest, $domain);

    foreach ([$domain, $neighbour] as $owner) {
        foreach (['/page', '/index', '/space%20name', '/page?page=2', '/page?page=3', '/page?preview=1', '/other'] as $path) {
            $url = 'https://' . $owner->domain . $path;
            $selected = $url === $targetUrl;
            $row = CachedModelUrl::query()->create([
                'url' => $url, 'url_hash' => CachedModelUrl::hashUrl($url),
                'path' => Request::create($url)->getPathInfo(),
                'site_id' => $owner->site_id, 'site_domain_id' => $owner->id,
                'cacheable_type' => $selected ? $page->getMorphClass() : SiteDomain::class,
                'cacheable_id' => $selected ? $page->id : CachedModelUrl::query()->count() + 1,
                'cached_at' => now(), 'last_seen_at' => now(),
            ]);
            if ($selected) {
                $target = $row;
            }

            foreach ([null, 'articles', 'navigation'] as $seedFragment) {
                $attributableFragmentFiles = [];
                $request = invalidationMatrixRequest($url, $seedFragment);
                $selectedVariant = $selected && $supported && ($urlEntry || $fragment === null || $seedFragment === $fragment);
                if ($selectedVariant) {
                    $targetRequests[] = $request;
                }
                if (! $paths->hasSafeKey($request)) {
                    // Unsupported requests must never read or delete a historical
                    // artefact merely because their query cannot produce a suffix.
                    $files = ['https.' . $owner->domain . '/page~unsupported-' . ($seedFragment ?? 'full') . '.html'];
                } else {
                    foreach ([200, 404] as $status) {
                        expect(resolve(PageCache::class)->cache($this->beginCacheRender($request), response('old:' . $url . ':' . ($seedFragment ?? 'full') . ':' . $status, $status, ['Content-Type' => 'text/html'])))->toBeTrue();
                    }
                    $files = [
                        $paths->pathForRequestUrl($request, $owner),
                        $paths->pathForRequestUrl($request, $owner, error: true),
                        ...$paths->historicalPathsForStoredPath($row->path, $request, $owner),
                    ];
                    if ($seedFragment !== null) {
                        $fullRequest = Request::create($url);
                        $fullFiles = [
                            $paths->pathForRequestUrl($fullRequest, $owner),
                            $paths->pathForRequestUrl($fullRequest, $owner, error: true),
                            ...$paths->historicalPathsForStoredPath($row->path, $fullRequest, $owner),
                        ];
                        foreach ($fullFiles as $file) {
                            $attributableFragmentFiles[] = preg_replace(
                                '/(?=(?:\.404)?\.html$)/',
                                '~f' . mb_substr(hash('xxh128', $seedFragment), 0, 16),
                                $file,
                                limit: 1,
                            ) ?? throw new RuntimeException('Unable to build an independently keyed fragment fixture.');
                        }
                        $files = [...$files, ...$attributableFragmentFiles];
                    }
                }
                foreach (array_unique($files) as $file) {
                    $ownerKey = $url . ':' . ($seedFragment ?? 'full');
                    expect($seedOwners[$file] ?? $ownerKey)->toBe($ownerKey, 'Distinct URL/fragment owners must never share a stored key: ' . $file);
                    $seedOwners[$file] = $ownerKey;
                    $body = 'old:' . $file;
                    Storage::disk('page_cache')->put($file, $body);
                    Storage::disk('page_cache')->put($file . PageCache::FRAGMENT_METADATA_EXTENSION, json_encode(new RenderHookFragmentCacheData($body, [])->metadata(), JSON_THROW_ON_ERROR));
                    $pagePathRequest = Request::create($url);
                    $pagePathRequest->query->replace([]);
                    // Opaque legacy hashes cannot distinguish fragments from untracked
                    // full query responses; evict unattributable artefacts on this path.
                    $unattributableFragment = $supported && $seedFragment !== null
                        && $owner->id === $domain->id && $paths->hasSafeKey($request)
                        && ! in_array($file, $attributableFragmentFiles, true)
                        && $paths->pathForRequestUrl($pagePathRequest, $owner) === $targetBase;
                    if ($selectedVariant || $unattributableFragment) {
                        $targetFiles[] = $file;
                        $targetFiles[] = $file . PageCache::FRAGMENT_METADATA_EXTENSION;
                    }
                }
            }
        }
    }
    assert($target instanceof CachedModelUrl);
    $beforeFiles = invalidationMatrixFiles();
    expect($beforeFiles)->toHaveCount(332);
    $beforeRows = invalidationMatrixRows();
    $expectedFiles = array_diff_key($beforeFiles, array_fill_keys($targetFiles, true));
    $expectedRows = $beforeRows;
    if ($supported) {
        unset($expectedRows[$target->id]);
    }
    $request = invalidationMatrixRequest($targetUrl, $urlEntry ? null : $fragment);
    $cache = resolve(PageCache::class);
    $private = static fn (): Response => response('fresh private output', 200, ['Content-Type' => 'text/html', 'Cache-Control' => 'private']);

    if (in_array($entry, ['queued refresh', 'process-stale', 'model mark-stale', 'site mark-stale', 'all mark-stale'], true)) {
        if ($entry === 'model mark-stale') {
            expect(MarkCachedUrlsForModelStaleAction::run($page))->toBe($supported ? 1 : 0);
        } elseif ($entry === 'site mark-stale') {
            expect(MarkCachedUrlsForSiteStaleAction::run($domain->site_id))->toBe(6);
        } elseif ($entry === 'all mark-stale') {
            expect(MarkAllCachedUrlsStaleAction::run())->toBe(12);
        } else {
            expect(MarkCachedUrlStaleAction::run($target))->toBe($supported ? 1 : 0);
        }
        $markedUrls = StaleCachedUrl::query()->orderBy('url')->pluck('url')->all();
        $expectedMarkedUrls = [];
        foreach ($beforeRows as $row) {
            $storedUrl = $row['url'];
            if (! is_string($storedUrl)) {
                throw new RuntimeException('A matrix cache tracking row must contain a string URL.');
            }
            if ($paths->hasSafeKey($storedUrl) && match ($entry) {
                'all mark-stale' => true,
                'site mark-stale' => $row['site_id'] === $domain->site_id,
                default => $row['id'] === $target->id,
            }) {
                $expectedMarkedUrls[] = $storedUrl;
            }
        }
        sort($expectedMarkedUrls);
        expect($markedUrls)->toBe($expectedMarkedUrls)
            ->and(invalidationMatrixFiles())->toBe($beforeFiles)
            ->and(invalidationMatrixRows())->toBe($beforeRows);
        Route::get('/{matrixPath?}', $private)->where('matrixPath', '.*')->middleware(HtmlCacheMiddleware::class);
        if ($supported) {
            StaleCachedUrl::query()->where('url', $targetUrl)->update(['created_at' => now()->subHour()]);
            $otherClaims = StaleCachedUrl::query()->where('url', '!=', $targetUrl)->get()->map->getRawOriginal()->all();
            if ($entry === 'queued refresh') {
                new RefreshOriginStaleCachedUrlJob($targetUrl)->handle();
            } else {
                expect(ProcessStaleHtmlCacheAction::run(1)->notApplicable)->toBe(1);
            }
            expect(StaleCachedUrl::query()->where('url', $targetUrl)->firstOrFail()->status)->toBe(StaleCachedUrl::STATUS_NOT_APPLICABLE)
                ->and(StaleCachedUrl::query()->where('url', '!=', $targetUrl)->get()->map->getRawOriginal()->all())->toBe($otherClaims);
        }
    } elseif ($entry === 'static generation') {
        config()->set('capell-html-cache.static_generation.internal_requests', true);
        Route::get('/{matrixPath?}', $private)->where('matrixPath', '.*')->middleware($supported ? [HtmlCacheMiddleware::class] : []);
        $registry = new StaticSiteExtensionRegistry;
        $registry->register('matrix', static function (Site $site, SiteDomain $siteDomain, Closure $visit) use ($targetUrl): void {
            $visit($targetUrl);
        });
        app()->instance(StaticSiteExtensionRegistry::class, $registry);
        new StaticSiteGenerator($domain->site)->process();
    } elseif ($entry === 'retirement') {
        expect(retireDeclaredHtmlCacheOriginResponse($request, $private()) !== null)->toBe($supported);
    } elseif ($entry === 'string clear' || $entry === 'row clear') {
        expect(ClearCachedUrlAction::run($entry === 'row clear' ? $target : $targetUrl, $domain))->toBe($supported);
    } elseif ($entry === 'frontend-authoring clear') {
        expect(ClearAffectedCachedUrlsAction::run($page, [$targetUrl], 'https://example.test/unrelated'))->toBe($supported ? 1 : 0);
    } elseif ($entry === 'PageCache forget') {
        expect($cache->forget($request->getRequestUri()))->toBe($supported);
    } elseif ($entry === 'CLI clear') {
        $this->artisan('capell:html-cache:clear', ['slug' => $request->getRequestUri()])->assertSuccessful();
    } else {
        if ($entry === 'middleware serve' && $supported) {
            $served = resolve(HtmlCacheMiddleware::class)->handle($request, static fn (): never => throw new RuntimeException('A seeded cache hit must not render origin.'));
            expect($served->getContent())->toBe('old:' . $paths->pathForRequestUrl($request, $domain));
        }
        $request->attributes->set(HtmlCacheMiddleware::BYPASS_CACHE_READ_ATTRIBUTE, true);
        $body = match ($entry) {
            'middleware CSRF safety' => '<form><input type="hidden" name="_token" value="session-token"></form>',
            'middleware authoring safety' => '<main data-capell-editor="1">private output</main>',
            default => 'fresh private output',
        };
        $status = match ($entry) {
            'middleware synthetic 301', 'middleware disabled 301' => 301,
            'middleware synthetic 308', 'middleware disabled 308' => 308,
            default => 200,
        };
        $request->attributes->set(HtmlCacheMiddleware::SYNTHETIC_RENDER_ATTRIBUTE, str_starts_with($entry, 'middleware synthetic '));
        config()->set('capell-html-cache.enabled', ! str_starts_with($entry, 'middleware disabled '));
        if ($entry === 'middleware non-HTML fragment') {
            app()->instance(RenderHookFragmentRegistry::class, new RenderHookFragmentRegistry);
            $hooks = new RenderHookRegistry;
            $hooks->contribute(RenderHookContributionData::inlineBlade(
                RenderHookLocation::BodyEnd,
                '',
                'matrix',
                'empty-fragment',
                fragment: true,
            ));
            app()->instance(RenderHookRegistry::class, $hooks);
        }
        $origin = static function () use ($entry, $body, $status): Response {
            if ($entry === 'middleware missing capture') {
                app()->offsetUnset(RenderHookFragmentRegistry::class);
            }
            $fragment = $entry === 'middleware non-HTML fragment'
                ? resolve(RenderHookRegistry::class)->renderAll(RenderHookLocation::BodyEnd)
                : '';

            return response($body . $fragment, $status, [
                'Content-Type' => $entry === 'middleware non-HTML fragment' ? 'application/json' : 'text/html',
                'Cache-Control' => 'private, no-store',
            ]);
        };
        $response = resolve(HtmlCacheMiddleware::class)->handle($request, $origin);
        expect($response->getContent())->toBe($body)
            ->and($response->getStatusCode())->toBe($status);
        // Restore capture services for the fresh public write/read acceptance checks.
        app()->instance(RenderHookFragmentRegistry::class, new RenderHookFragmentRegistry);
        config()->set('capell-html-cache.enabled', true);
        if (str_starts_with($entry, 'middleware disabled ')) {
            // Disabled caching must preserve artefacts on automatic retirement.
            expect(invalidationMatrixFiles())->toBe($beforeFiles)
                ->and(invalidationMatrixRows())->toBe($beforeRows);
            foreach ($targetRequests as $storedRequest) {
                app()->instance('request', $storedRequest);
                $stored = resolve(HtmlCacheMiddleware::class)->handle($storedRequest, static fn (): never => throw new RuntimeException('Disabled caching must preserve the previous inventory.'));
                expect($stored->headers->get('X-Frontend-Cache'))->toBe('HIT')
                    ->and($stored->getContent())->toBe('old:' . $paths->pathForRequestUrl($storedRequest, $domain));
            }
            PurgeEdgeCacheAction::assertNotPushed();

            return;
        }
    }

    expect(invalidationMatrixFiles())->toBe($expectedFiles)
        ->and(invalidationMatrixRows())->toBe($expectedRows);
    foreach ($targetRequests === [] ? [$request] : $targetRequests as $nextRequest) {
        app()->instance('request', $nextRequest);
        expect($cache->getCachePage($nextRequest))->toBeFalse()
            ->and($cache->getCacheErrorPage($nextRequest))->toBeFalse();
        $next = resolve(HtmlCacheMiddleware::class)->handle($nextRequest, $private);
        expect($next->getContent())->toBe('fresh private output')
            ->and($next->getStatusCode())->toBe(200);
        $missingRequest = invalidationMatrixRequest($nextRequest->fullUrl(), $nextRequest->headers->get(StatelessPaginationRequest::FRAGMENT_HEADER));
        $missing = resolve(HtmlCacheMiddleware::class)->handle($missingRequest, static fn (): Response => response('fresh error output', 404, ['Content-Type' => 'text/html', 'Cache-Control' => 'private']));
        expect($missing->getContent())->toBe('fresh error output')
            ->and($missing->getStatusCode())->toBe(404);
    }
    expect(invalidationMatrixFiles())->toBe($expectedFiles)
        ->and(invalidationMatrixRows())->toBe($expectedRows);
    foreach ($targetRequests as $nextRequest) {
        $freshRequest = invalidationMatrixRequest($nextRequest->fullUrl(), $nextRequest->headers->get(StatelessPaginationRequest::FRAGMENT_HEADER));
        $fresh = resolve(HtmlCacheMiddleware::class)->handle($freshRequest, static fn (): Response => response('fresh public output', 200, ['Content-Type' => 'text/html', 'Cache-Control' => 'public']));
        expect($fresh->getContent())->toBe('fresh public output');
        $hitRequest = invalidationMatrixRequest($nextRequest->fullUrl(), $nextRequest->headers->get(StatelessPaginationRequest::FRAGMENT_HEADER));
        $hit = resolve(HtmlCacheMiddleware::class)->handle($hitRequest, static fn (): never => throw new RuntimeException('The next public read must serve the freshly stored output.'));
        expect($hit->getContent())->toBe('fresh public output')
            ->and($hit->getStatusCode())->toBe(200);
    }
    expect(array_diff_key(invalidationMatrixFiles(), array_fill_keys($targetFiles, true)))->toBe($expectedFiles)
        ->and(array_intersect_key(invalidationMatrixRows(), $expectedRows))->toBe($expectedRows);
})->with([
    'middleware store', 'middleware serve', 'queued refresh', 'process-stale', 'static generation',
    'middleware CSRF safety', 'middleware authoring safety', 'middleware synthetic 301',
    'middleware synthetic 308', 'middleware non-HTML fragment', 'middleware missing capture',
    'middleware disabled 301', 'middleware disabled 308',
    'retirement', 'string clear', 'row clear', 'PageCache forget', 'CLI clear',
    'model mark-stale', 'site mark-stale', 'all mark-stale', 'frontend-authoring clear',
])->with(['/page', '/index', '/space%20name', '/page?page=2', '/page?page=3', '/page?preview=1'])
    ->with(['full response' => null, 'articles fragment' => 'articles', 'navigation fragment' => 'navigation']);

it('guards retirement on a natural middleware HTML safety miss', function (int $status, bool $suppressPurge, bool $visitor, bool $privatePolicy): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    bindHtmlCacheFrontendContext();
    $paths = resolve(HtmlCachePathResolver::class);
    $url = 'https://example.test/page';
    $fragmentRequest = invalidationMatrixRequest($url, 'articles');
    $fragmentPath = $paths->pathForRequestUrl($fragmentRequest, $domain);
    Storage::disk('page_cache')->put($fragmentPath, 'old fragment');
    CachedModelUrl::query()->create([
        'url' => $url, 'url_hash' => CachedModelUrl::hashUrl($url), 'path' => '/page',
        'site_id' => $domain->site_id, 'site_domain_id' => $domain->id,
        'cacheable_type' => SiteDomain::class, 'cacheable_id' => $domain->id,
        'cached_at' => now(), 'last_seen_at' => now(),
    ]);
    $beforeFiles = invalidationMatrixFiles();
    $beforeRows = invalidationMatrixRows();
    $request = invalidationMatrixRequest($url, null);
    $request->attributes->set(HtmlCacheMiddleware::SUPPRESS_INLINE_EDGE_PURGE_ATTRIBUTE, $suppressPurge);
    if ($visitor) {
        $request->cookies->set(config()->string('session.cookie'), 'visitor-session');
    }
    $body = '<form><input type="hidden" name="_token" value="session-token"></form>';
    $response = resolve(HtmlCacheMiddleware::class)->handle($request, static fn (): Response => response($body, $status, [
        'Content-Type' => 'text/html', 'Cache-Control' => $privatePolicy ? 'private, no-store' : 'public',
    ]));
    $retires = ! $visitor && (($privatePolicy && $status >= 200 && $status < 300) || in_array($status, [301, 308], true));
    expect($response->getContent())->toBe($body)
        ->and($response->getStatusCode())->toBe($status)
        ->and($response->headers->get('Cache-Control'))->toContain('private', 'no-store')
        ->and(invalidationMatrixFiles())->toBe($retires ? [] : $beforeFiles)
        ->and(invalidationMatrixRows())->toBe($retires ? [] : $beforeRows);
    if ($retires && ! $suppressPurge) {
        PurgeEdgeCacheAction::assertPushed();
    } else {
        PurgeEdgeCacheAction::assertNotPushed();
    }
    $nextRequest = invalidationMatrixRequest($url, 'articles');
    $next = resolve(HtmlCacheMiddleware::class)->handle($nextRequest, static fn (): Response => response('fresh fragment', 200, [
        'Content-Type' => 'text/html', 'Cache-Control' => 'private, no-store',
    ]));
    expect($next->getContent())->toBe($retires ? 'fresh fragment' : 'old fragment')
        ->and($next->headers->get('X-Frontend-Cache'))->toBe($retires ? 'MISS' : 'HIT');
})->with([200, 204, 206, 301, 308, 302, 404, 500])->with([false, true])->with([false, true])->with([false, true]);

it('preserves previous artefacts on transient middleware write rejections', function (string $rejection): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    bindHtmlCacheFrontendContext();
    $url = 'https://example.test/page';
    $fragmentRequest = invalidationMatrixRequest($url, 'articles');
    $path = resolve(HtmlCachePathResolver::class)->pathForRequestUrl($fragmentRequest, $domain);
    Storage::disk('page_cache')->put($path, 'old fragment');
    $beforeFiles = invalidationMatrixFiles();
    $beforeRows = invalidationMatrixRows();
    $request = invalidationMatrixRequest($url, null);
    $request->attributes->set(HtmlCacheMiddleware::SYNTHETIC_RENDER_ATTRIBUTE, $rejection === 'synthetic 302');
    $response = resolve(HtmlCacheMiddleware::class)->handle($request, static function (Request $request) use ($rejection): Response {
        if ($rejection === 'fragment failure') {
            $request->attributes->set(HtmlCacheMiddleware::FRAGMENT_RENDER_FAILED_ATTRIBUTE, true);
        } elseif ($rejection === 'publication race') {
            resolve(HtmlCachePublicationGuard::class)->invalidate(static fn (): bool => true);
        } elseif ($rejection === 'missing capture') {
            app()->offsetUnset(RenderHookFragmentRegistry::class);
        }

        return response('fresh output', $rejection === 'synthetic 302' ? 302 : 200, [
            'Content-Type' => 'text/html',
            'Cache-Control' => $rejection === 'fragment failure' ? 'private, no-store' : 'public',
        ]);
    });
    expect($response->getContent())->toBe('fresh output')
        ->and($request->attributes->get(HtmlCacheMiddleware::CACHE_WRITE_SUCCEEDED_ATTRIBUTE))->toBeFalse()
        ->and(invalidationMatrixFiles())->toBe($beforeFiles)
        ->and(invalidationMatrixRows())->toBe($beforeRows);
    PurgeEdgeCacheAction::assertNotPushed();
})->with(['fragment failure', 'publication race', 'synthetic 302', 'missing capture']);

it('keeps transient origin rejections retryable across cache consumers', function (string $entry, string $rejection): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    bindHtmlCacheFrontendContext();
    $url = 'https://example.test/page';
    $paths = resolve(HtmlCachePathResolver::class);
    foreach ([$url, 'https://example.test/neighbour'] as $seedUrl) {
        foreach ([null, 'articles'] as $fragment) {
            $seedRequest = invalidationMatrixRequest($seedUrl, $fragment);
            foreach ([false, true] as $error) {
                $file = $paths->pathForRequestUrl($seedRequest, $domain, error: $error);
                Storage::disk('page_cache')->put($file, 'old:' . $file);
                Storage::disk('page_cache')->put($file . PageCache::FRAGMENT_METADATA_EXTENSION, json_encode(new RenderHookFragmentCacheData('old:' . $file, [])->metadata(), JSON_THROW_ON_ERROR));
            }
        }
        CachedModelUrl::query()->create([
            'url' => $seedUrl, 'url_hash' => CachedModelUrl::hashUrl($seedUrl),
            'path' => Request::create($seedUrl)->getPathInfo(),
            'site_id' => $domain->site_id, 'site_domain_id' => $domain->id,
            'cacheable_type' => SiteDomain::class, 'cacheable_id' => $domain->id,
        ]);
    }
    $beforeFiles = invalidationMatrixFiles();
    $beforeRows = invalidationMatrixRows();
    $origin = new class
    {
        public bool $reject = true;
    };
    Route::get('/page', static function (Request $request) use ($rejection, $origin): Response {
        if ($origin->reject) {
            if ($rejection === 'publication race') {
                resolve(HtmlCachePublicationGuard::class)->invalidate(static fn (): bool => true);
            } elseif ($rejection === 'fragment failure') {
                $request->attributes->set(HtmlCacheMiddleware::FRAGMENT_RENDER_FAILED_ATTRIBUTE, true);
            } elseif ($rejection === 'missing capture') {
                app()->offsetUnset(RenderHookFragmentRegistry::class);
            } elseif ($rejection === 'render exception') {
                throw new RuntimeException('Origin rendering failed.');
            }
        }

        return response($origin->reject ? match ($rejection) {
            'CSRF safety' => '<form><input type="hidden" name="_token" value="session-token"></form>',
            'authoring safety' => '<main data-capell-editor="1">private output</main>',
            default => 'fresh output',
        } : 'fresh output', 200, ['Content-Type' => 'text/html', 'Cache-Control' => 'public']);
    })->middleware(HtmlCacheMiddleware::class);

    $stale = null;
    $checkpoints = [];
    if ($entry === 'static generation') {
        config()->set('capell-html-cache.static_generation.internal_requests', true);
        $registry = new StaticSiteExtensionRegistry;
        $registry->register('transient-matrix', static function (Site $site, SiteDomain $siteDomain, Closure $visit) use ($url): void {
            $visit($url);
        });
        app()->instance(StaticSiteExtensionRegistry::class, $registry);
        $attempt = static function () use ($domain, &$checkpoints): void {
            new StaticSiteGenerator($domain->site)->process(checkpoint: static function (string $url) use (&$checkpoints): void {
                $checkpoints[] = $url;
            });
        };
    } else {
        expect(MarkCachedUrlStaleAction::run(CachedModelUrl::query()->where('url', $url)->firstOrFail()))->toBe(1);
        $stale = StaleCachedUrl::query()->where('url', $url)->firstOrFail();
        $attempt = static function () use ($entry, $url, $stale): void {
            if ($entry === 'refresh') {
                expect(ClaimStaleCachedUrlAction::run($stale))->toBeTrue();
                RefreshCachedUrlAtomicallyAction::run($stale->refresh());
            } else {
                new RefreshOriginStaleCachedUrlJob($url)->handle();
            }
        };
    }

    if ($entry === 'process-stale') {
        $result = ProcessStaleHtmlCacheAction::run(1);
        expect($result->attempted)->toBe(1)
            ->and($result->failed)->toBe(1)
            ->and($result->notApplicable)->toBe(0);
    } else {
        $failure = null;
        try {
            $attempt();
        } catch (RuntimeException $exception) {
            $failure = $exception;
        }
        expect($failure)->toBeInstanceOf(RuntimeException::class)
            ->not->toBeInstanceOf(StaleCachedUrlNotApplicableException::class);
    }
    expect(invalidationMatrixFiles())->toBe($beforeFiles)
        ->and(invalidationMatrixRows())->toBe($beforeRows)
        ->and($checkpoints)->toBe([]);
    PurgeEdgeCacheAction::assertNotPushed();
    if ($stale instanceof StaleCachedUrl) {
        expect($stale->refresh()->status)->toBe($entry === 'refresh' ? StaleCachedUrl::STATUS_PROCESSING : StaleCachedUrl::STATUS_FAILED);
        $stale->update(['status' => StaleCachedUrl::STATUS_FAILED, 'failed_at' => now()->subHour()]);
    }

    $origin->reject = false;
    app()->instance(RenderHookFragmentRegistry::class, new RenderHookFragmentRegistry);
    if ($entry === 'process-stale') {
        $result = ProcessStaleHtmlCacheAction::run(1);
        expect($result->succeeded)->toBe(1)->and($result->failed)->toBe(0);
    } else {
        $attempt();
    }
    $request = invalidationMatrixRequest($url, null);
    expect(Storage::disk('page_cache')->get($paths->pathForRequestUrl($request, $domain)))->toBe('fresh output');
    if ($entry === 'static generation') {
        expect($checkpoints)->toBe([$url]);
    } elseif ($entry !== 'refresh') {
        expect($stale?->refresh()->status)->toBe(StaleCachedUrl::STATUS_PROCESSED);
    }
})->with(['refresh', 'queued refresh', 'process-stale', 'static generation'])
    ->with(['CSRF safety', 'authoring safety', 'publication race', 'fragment failure', 'missing capture', 'render exception']);

it('preserves disabled cache artefacts for configured bypass redirects', function (int $status, string $entry): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    bindHtmlCacheFrontendContext();
    $url = 'https://example.test/page';
    $request = invalidationMatrixRequest($url, null);
    $path = resolve(HtmlCachePathResolver::class)->pathForRequestUrl($request, $domain);
    Storage::disk('page_cache')->put($path, 'old public output');
    Storage::disk('page_cache')->put($path . PageCache::FRAGMENT_METADATA_EXTENSION, 'old sidecar');
    CachedModelUrl::query()->create([
        'url' => $url, 'url_hash' => CachedModelUrl::hashUrl($url), 'path' => '/page',
        'site_id' => $domain->site_id, 'site_domain_id' => $domain->id,
        'cacheable_type' => SiteDomain::class, 'cacheable_id' => $domain->id,
    ]);
    $beforeFiles = invalidationMatrixFiles();
    $beforeRows = invalidationMatrixRows();
    config()->set('capell-html-cache.enabled', false);
    config()->set('capell-html-cache.bypass.paths', ['page']);
    $origin = response('redirect output', $status, ['Location' => '/destination', 'Content-Type' => 'text/html']);
    if ($entry === 'retirement') {
        expect(retireDeclaredHtmlCacheOriginResponse($request, $origin))->toBeNull();
    } else {
        $response = resolve(HtmlCacheMiddleware::class)->handle($request, static fn (): Response => $origin);
        expect($response->getStatusCode())->toBe($status);
    }
    expect(invalidationMatrixFiles())->toBe($beforeFiles)
        ->and(invalidationMatrixRows())->toBe($beforeRows);
    PurgeEdgeCacheAction::assertNotPushed();
})->with([301, 308])->with(['retirement', 'middleware']);

// Invariants 1/2/3/5/6: origin publication and retirement are different outcomes.
it('replaces old successful HTML with a refreshed 404 across origin consumers', function (string $entry, bool $suppressPurge): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    bindHtmlCacheFrontendContext();
    $url = 'https://example.test/page';
    $row = invalidationMatrixSeed($url, $domain);
    invalidationMatrixSeed('https://example.test/neighbour', $domain);
    $before = invalidationMatrixFiles();
    Route::get('/page', static fn (): Response => response('fresh missing page', 404, ['Content-Type' => 'text/html', 'Cache-Control' => 'public']))->middleware(HtmlCacheMiddleware::class);
    expect(MarkCachedUrlStaleAction::run($row))->toBe(1);
    $stale = StaleCachedUrl::query()->where('url', $url)->firstOrFail();
    if ($entry === 'refresh') {
        expect(ClaimStaleCachedUrlAction::run($stale))->toBeTrue();
        RefreshCachedUrlAtomicallyAction::run($stale->refresh(), $suppressPurge);
    } elseif ($entry === 'queued refresh') {
        new RefreshOriginStaleCachedUrlJob($url)->handle();
    } else {
        expect(ProcessStaleHtmlCacheAction::run(1, $suppressPurge))->toHaveProperties(['attempted' => 1, 'succeeded' => 1, 'failed' => 0]);
    }
    $request = invalidationMatrixRequest($url, null);
    $paths = resolve(HtmlCachePathResolver::class);
    $html = $paths->pathForRequestUrl($request, $domain);
    $error = $paths->pathForRequestUrl($request, $domain, error: true);
    $expected = $before;
    unset($expected[$html], $expected[$html . PageCache::FRAGMENT_METADATA_EXTENSION], $expected[$error . PageCache::FRAGMENT_METADATA_EXTENSION]);
    // Legacy hashed variants of this page cannot be attributed to a query or fragment,
    // and a surviving 200 variant would keep serving the removed page; they are evicted.
    $legacyVariantPrefix = substr($html, 0, -strlen('.html')) . '~';
    $expected = array_filter($expected, static fn (string $file): bool => ! str_starts_with($file, $legacyVariantPrefix), ARRAY_FILTER_USE_KEY);
    $expected[$error] = 'fresh missing page';
    ksort($expected);
    expect(invalidationMatrixFiles())->toBe($expected);
    $hit = resolve(HtmlCacheMiddleware::class)->handle($request, static fn (): never => throw new RuntimeException('The replaced 404 must be served from cache.'));
    expect($hit->getStatusCode())->toBe(404)->and($hit->getContent())->toBe('fresh missing page');
    if ($entry !== 'refresh') {
        expect($stale->refresh()->status)->toBe(StaleCachedUrl::STATUS_PROCESSED);
    }
    if ($suppressPurge) {
        PurgeEdgeCacheAction::assertNotPushed();
    } else {
        PurgeEdgeCacheAction::assertPushed();
    }
})->with([['refresh', false], ['refresh', true], ['queued refresh', false], ['process-stale', false], ['process-stale', true]]);

it('uses the origin decision despite outer middleware rewriting cache headers', function (string $entry, string $originMode): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    bindHtmlCacheFrontendContext();
    $url = 'https://example.test/page';
    $row = invalidationMatrixSeed($url, $domain);
    $before = invalidationMatrixFiles();
    $beforeRows = invalidationMatrixRows();
    config()->set('capell-html-cache.enabled', $originMode !== 'disabled');
    if ($originMode === 'resolver bypass') {
        $resolver = Mockery::mock(CacheBypassResolver::class);
        $resolver->shouldReceive('shouldBypass')->andReturnTrue();
        app()->instance(CacheBypassResolver::class, $resolver);
    }
    Route::get('/page', static fn (): Response => response($originMode === 'rejected' ? '<main data-capell-editor="1">private output</main>' : 'fresh output', 200, ['Content-Type' => 'text/html', 'Cache-Control' => 'public']))
        ->middleware([InvalidationMatrixOuterHeaders::class, HtmlCacheMiddleware::class]);
    $failure = null;
    $checkpoints = [];
    if ($entry === 'static generation') {
        config()->set('capell-html-cache.static_generation.internal_requests', true);
        $registry = new StaticSiteExtensionRegistry;
        $registry->register('headers', static function (Site $site, SiteDomain $domain, Closure $visit) use ($url): void {
            $visit($url);
        });
        app()->instance(StaticSiteExtensionRegistry::class, $registry);
        try {
            new StaticSiteGenerator($domain->site)->process(checkpoint: static function (string $url) use (&$checkpoints): void {
                $checkpoints[] = $url;
            });
        } catch (RuntimeException $exception) {
            $failure = $exception;
        }
        expect($failure === null)->toBe($originMode === 'published')->and($checkpoints)->toBe($originMode === 'published' ? [$url] : []);
    } else {
        // Mark before disabling automatic cache refresh.
        config()->set('capell-html-cache.enabled', true);
        expect(MarkCachedUrlStaleAction::run($row))->toBe(1);
        config()->set('capell-html-cache.enabled', $originMode !== 'disabled');
        $stale = StaleCachedUrl::query()->where('url', $url)->firstOrFail();
        if ($entry === 'refresh') {
            expect(ClaimStaleCachedUrlAction::run($stale))->toBeTrue();
            try {
                RefreshCachedUrlAtomicallyAction::run($stale->refresh());
            } catch (RuntimeException $exception) {
                $failure = $exception;
            }
            expect($failure === null)->toBe($originMode === 'published');
        } elseif ($entry === 'queued refresh') {
            try {
                new RefreshOriginStaleCachedUrlJob($url)->handle();
            } catch (RuntimeException $exception) {
                $failure = $exception;
            }
            expect($failure === null)->toBe($originMode === 'published');
        } else {
            $result = ProcessStaleHtmlCacheAction::run(1);
            expect($result->notApplicable)->toBe(0)->and($result->failed)->toBe($originMode === 'published' ? 0 : 1);
        }
    }
    if ($originMode === 'published') {
        expect(Storage::disk('page_cache')->get(resolve(HtmlCachePathResolver::class)->pathForRequestUrl($url, $domain)))->toBe('fresh output');
    } else {
        expect(invalidationMatrixFiles())->toBe($before)->and(invalidationMatrixRows())->toBe($beforeRows);
        PurgeEdgeCacheAction::assertNotPushed();
    }
})->with(['refresh', 'queued refresh', 'process-stale', 'static generation'])->with(['published', 'rejected', 'disabled', 'resolver bypass']);

// Invariant 7: observer and job clears must survive an unrepairable dependency index.
it('clears unresolvable legacy tracking without guessing filenames or throwing', function (string $entry, bool $legacySite): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    // Translations create separately resolvable PageUrls; this model owns only the orphan URL.
    $page = Page::factory()->recycle($domain->site)->create();
    $url = 'https://missing.test/page';
    $row = CachedModelUrl::query()->create([
        'url' => $url, 'url_hash' => CachedModelUrl::hashUrl($url), 'path' => '/page',
        'site_id' => $legacySite ? $domain->site_id : null, 'site_domain_id' => null,
        'cacheable_type' => $page->getMorphClass(), 'cacheable_id' => $page->id,
    ]);
    expect(ResolveCachedUrlsForModelAction::run($page)->all())->toBe([$url]);
    Storage::disk('page_cache')->put('https.missing.test/page.html', 'unattributable historical output');
    invalidationMatrixSeed('https://example.test/page', $domain);
    $before = invalidationMatrixFiles();
    $beforeRows = invalidationMatrixRows();
    unset($beforeRows[$row->id]);
    if ($entry === 'model clear') {
        expect(ClearCachedUrlsForModelAction::run($page))->toBe(0);
    } elseif ($entry === 'surrogate clear') {
        expect(ClearCachedUrlsForSurrogateKeysAction::run(['page-' . $page->id]))->toBe(0);
    } elseif ($entry === 'page URL clear') {
        expect(ClearCachedPageUrlsAction::run(collect([$url])))->toBe(0);
    } elseif ($entry === 'frontend-authoring clear') {
        expect(ClearAffectedCachedUrlsAction::run($page, [$url], 'https://example.test/'))->toBe(0);
    } elseif ($entry === 'row clear job') {
        ClearCachedUrlAction::makeJob($row)->handle();
    } else {
        expect(ClearCachedUrlAction::run($entry === 'string clear' ? $url : $row))->toBeFalse();
    }
    expect(invalidationMatrixFiles())->toBe($before)->and(invalidationMatrixRows())->toBe($beforeRows);
    PurgeEdgeCacheAction::assertPushed();
})->with(['string clear', 'row clear', 'row clear job', 'frontend-authoring clear', 'model clear', 'surrogate clear', 'page URL clear'])->with([false, true]);

it('limits recorded ownership reads to the target path instead of hydrating the domain index', function (): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    invalidationMatrixSeed('https://example.test/page?page=2', $domain);
    invalidationMatrixSeed('https://example.test/page?page=3', $domain);
    for ($i = 0; $i < 20; $i++) {
        invalidationMatrixSeed('https://example.test/other-' . $i, $domain);
    }
    $hydrated = [];
    CachedModelUrl::retrieved(static function (CachedModelUrl $row) use (&$hydrated): void {
        $hydrated[] = $row->path;
    });
    DeleteCachedUrlArtefactsAction::run(Request::create('https://example.test/page?page=2'), $domain, includeVariants: true);
    expect($hydrated)->not->toBeEmpty()->and(array_unique($hydrated))->toBe(['/page']);
});

it('uses shared eviction for static refresh including fragments historical keys and sidecars', function (): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $page = Page::factory()->recycle($domain->site)->withTranslations()->create();
    $page->pageUrls()->delete();
    $page->blueprint->update(['status' => true]);
    PageUrl::query()->forceCreate([
        'site_id' => $domain->site_id, 'language_id' => $domain->language_id,
        'pageable_type' => $page->getMorphClass(), 'pageable_id' => $page->id,
        'url' => '/space%20name', 'status' => true,
    ]);
    bindHtmlCacheFrontendContext($page);
    $url = 'https://example.test/space%20name';
    $row = invalidationMatrixSeed($url, $domain);
    $historical = resolve(HtmlCachePathResolver::class)->historicalPathsForStoredPath($row->path, Request::create($url), $domain);
    foreach ($historical as $file) {
        Storage::disk('page_cache')->put($file, 'old historical HTML');
        Storage::disk('page_cache')->put($file . PageCache::FRAGMENT_METADATA_EXTENSION, 'old historical metadata');
    }
    invalidationMatrixSeed('https://example.test/neighbour', $domain);
    $before = invalidationMatrixFiles();
    $expected = array_filter($before, static fn (string $file): bool => str_contains($file, '/neighbour'), ARRAY_FILTER_USE_KEY);
    $observed = false;
    $observedFiles = [];
    Route::get('/{matrixPath}', static function () use (&$observed, &$observedFiles): Response {
        $observed = true;
        $observedFiles = invalidationMatrixFiles();

        return response('fresh static output', 200, ['Content-Type' => 'text/html', 'Cache-Control' => 'public']);
    })->where('matrixPath', '.*')->middleware(HtmlCacheMiddleware::class);
    config()->set('capell-html-cache.static_generation.internal_requests', true);
    app()->instance(StaticSiteExtensionRegistry::class, new StaticSiteExtensionRegistry);
    new StaticSiteGenerator($domain->site, refresh: true)->process();
    expect($observed)->toBeTrue()->and($observedFiles)->toBe($expected);
});

it('surfaces exhausted transient refreshes in health while preserving their artefacts', function (string $entry): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    bindHtmlCacheFrontendContext();
    $url = 'https://example.test/page';
    $row = invalidationMatrixSeed($url, $domain);
    expect(MarkCachedUrlStaleAction::run($row))->toBe(1);
    config()->set('capell-html-cache.invalidation.max_attempts', 1);
    Route::get('/page', static fn (): Response => response('transient failure', 503, ['Content-Type' => 'text/html']))->middleware(HtmlCacheMiddleware::class);
    $before = invalidationMatrixFiles();
    if ($entry === 'queued refresh') {
        expect(static fn () => new RefreshOriginStaleCachedUrlJob($url)->handle())->toThrow(RuntimeException::class);
    } else {
        expect(ProcessStaleHtmlCacheAction::run(1)->failed)->toBe(1);
    }
    expect(StaleCachedUrl::query()->where('url', $url)->firstOrFail()->status)->toBe(StaleCachedUrl::STATUS_EXHAUSTED)
        ->and(invalidationMatrixFiles())->toBe($before)
        ->and(HtmlCacheHealthCheck::runDiagnostics()->contains(static fn ($result): bool => ! $result->passed && str_contains($result->message, 'exhausted')))->toBeTrue();
    PurgeEdgeCacheAction::assertNotPushed();
})->with(['queued refresh', 'process-stale']);

it('enforces accepted status origin reason disablement and purge suppression at every automatic entry', function (string $entry, int $status, bool $enabled, bool $suppressPurge): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    bindHtmlCacheFrontendContext();
    $url = 'https://example.test/page';
    $row = invalidationMatrixSeed($url, $domain);
    $neighbour = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'neighbour.test', 'path' => null]);
    invalidationMatrixSeed('https://neighbour.test/page', $neighbour);
    $beforeFiles = invalidationMatrixFiles();
    $beforeRows = invalidationMatrixRows();
    $origin = static function (Request $request) use ($status, $suppressPurge): Response {
        $request->attributes->set(HtmlCacheMiddleware::SUPPRESS_INLINE_EDGE_PURGE_ATTRIBUTE, $suppressPurge);

        return response('origin private output', $status, ['Content-Type' => 'text/html', 'Cache-Control' => 'private, no-store', 'Location' => '/destination']);
    };
    Route::get('/page', $origin)->middleware(HtmlCacheMiddleware::class);
    $retired = $enabled && (($status >= 200 && $status < 300) || in_array($status, [301, 308], true));
    $failure = null;
    $stale = null;
    if ($entry === 'middleware' || $entry === 'retirement') {
        config()->set('capell-html-cache.enabled', $enabled);
        $request = invalidationMatrixRequest($url, null);
        $request->attributes->set(HtmlCacheMiddleware::BYPASS_CACHE_READ_ATTRIBUTE, true);
        $request->attributes->set(HtmlCacheMiddleware::SUPPRESS_INLINE_EDGE_PURGE_ATTRIBUTE, $suppressPurge);
        if ($entry === 'middleware') {
            expect(resolve(HtmlCacheMiddleware::class)->handle($request, $origin)->getStatusCode())->toBe($status);
        } else {
            expect(retireDeclaredHtmlCacheOriginResponse($request, $origin($request)) !== null)->toBe($retired);
        }
    } elseif ($entry === 'static generation') {
        config()->set('capell-html-cache.enabled', $enabled);
        config()->set('capell-html-cache.static_generation.internal_requests', true);
        $registry = new StaticSiteExtensionRegistry;
        $registry->register('guards', static function (Site $site, SiteDomain $domain, Closure $visit) use ($url): void {
            $visit($url);
        });
        app()->instance(StaticSiteExtensionRegistry::class, $registry);
        try {
            new StaticSiteGenerator($domain->site)->process();
        } catch (RuntimeException $exception) {
            $failure = $exception;
        }
        expect($failure === null)->toBe($retired && $status < 300);
    } else {
        match ($entry) {
            'model mark-stale' => MarkCachedUrlsForModelStaleAction::run($domain),
            'site mark-stale' => MarkCachedUrlsForSiteStaleAction::run($domain->site_id),
            'all mark-stale' => MarkAllCachedUrlsStaleAction::run(),
            default => MarkCachedUrlStaleAction::run($row),
        };
        $stale = StaleCachedUrl::query()->where('url', $url)->firstOrFail();
        // Make the selected owner first, preserving all other queued claims.
        StaleCachedUrl::query()->whereKey($stale->id)->update(['created_at' => now()->subHour()]);
        config()->set('capell-html-cache.enabled', $enabled);
        if ($entry === 'refresh') {
            expect(ClaimStaleCachedUrlAction::run($stale))->toBeTrue();
            try {
                RefreshCachedUrlAtomicallyAction::run($stale->refresh(), $suppressPurge);
            } catch (RuntimeException $exception) {
                $failure = $exception;
            }
            expect($failure instanceof StaleCachedUrlNotApplicableException)->toBe($retired);
        } elseif ($entry === 'queued refresh') {
            try {
                new RefreshOriginStaleCachedUrlJob($url)->handle();
            } catch (RuntimeException $exception) {
                $failure = $exception;
            }
            expect($failure === null)->toBe($retired);
        } else {
            $result = ProcessStaleHtmlCacheAction::run(1, $suppressPurge);
            expect($result)->toHaveProperties(['attempted' => 1, 'notApplicable' => $retired ? 1 : 0, 'failed' => $retired ? 0 : 1]);
        }
        if ($entry !== 'refresh') {
            expect($stale->refresh()->status)->toBe($retired ? StaleCachedUrl::STATUS_NOT_APPLICABLE : StaleCachedUrl::STATUS_FAILED);
        }
    }
    $expectedFiles = $retired ? array_filter($beforeFiles, static fn (string $file): bool => str_starts_with($file, 'https.neighbour.test/'), ARRAY_FILTER_USE_KEY) : $beforeFiles;
    if ($retired) {
        unset($beforeRows[$row->id]);
    }
    expect(invalidationMatrixFiles())->toBe($expectedFiles)->and(invalidationMatrixRows())->toBe($beforeRows);
    if ($retired && ! $suppressPurge) {
        PurgeEdgeCacheAction::assertPushed();
    } else {
        PurgeEdgeCacheAction::assertNotPushed();
    }
})->with(['middleware', 'retirement', 'refresh', 'queued refresh', 'process-stale', 'static generation', 'model mark-stale', 'site mark-stale', 'all mark-stale'])
    ->with([200, 204, 301, 308, 302, 404, 503])->with([true, false])->with([false, true]);

it('does not infer retirement when no origin boundary declared a decision', function (): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    bindHtmlCacheFrontendContext();
    $url = 'https://example.test/page';
    invalidationMatrixSeed($url, $domain);
    $beforeFiles = invalidationMatrixFiles();
    $beforeRows = invalidationMatrixRows();
    expect(RetireCachedUrlAction::run(Request::create($url), response('outer private output', 200, ['Content-Type' => 'text/html', 'Cache-Control' => 'private, no-store'])))->toBeNull()
        ->and(invalidationMatrixFiles())->toBe($beforeFiles)->and(invalidationMatrixRows())->toBe($beforeRows);
    PurgeEdgeCacheAction::assertNotPushed();
});

it('allows explicit clears while caching is disabled without widening the stored URL scope', function (string $entry, string $path): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $url = 'https://example.test' . $path;
    $row = invalidationMatrixSeed($url, $domain);
    invalidationMatrixSeed('https://example.test/other?page=3', $domain);
    $before = invalidationMatrixFiles();
    $expected = array_filter($before, static fn (string $file): bool => str_contains($file, '/other'), ARRAY_FILTER_USE_KEY);
    $beforeRows = invalidationMatrixRows();
    unset($beforeRows[$row->id]);
    config()->set('capell-html-cache.enabled', false);
    invalidationMatrixRequest($url, null);
    if ($entry === 'CLI clear') {
        $this->artisan('capell:html-cache:clear', ['slug' => $path])->assertSuccessful();
    } elseif ($entry === 'PageCache forget') {
        expect(resolve(PageCache::class)->forget($path))->toBeTrue();
    } elseif ($entry === 'frontend-authoring clear') {
        expect(ClearAffectedCachedUrlsAction::run($domain, [$url], 'https://example.test/'))->toBe(1);
    } else {
        expect(ClearCachedUrlAction::run($entry === 'row clear' ? $row : $url, $domain))->toBeTrue();
    }
    expect(invalidationMatrixFiles())->toBe($expected)->and(invalidationMatrixRows())->toBe($beforeRows);
})->with(['string clear', 'row clear', 'PageCache forget', 'CLI clear', 'frontend-authoring clear'])->with(['/page', '/page?page=2']);

it('clears a same-site legacy row with no domain reference without attributing it to the current domain', function (): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $url = 'https://example.test/page';
    $row = CachedModelUrl::query()->create([
        'url' => $url, 'url_hash' => CachedModelUrl::hashUrl($url), 'path' => '/page',
        'site_id' => $domain->site_id, 'site_domain_id' => null,
        'cacheable_type' => SiteDomain::class, 'cacheable_id' => $domain->id,
    ]);
    Storage::disk('page_cache')->put('https.example.test/page.html', 'unattributable legacy HTML');
    $before = invalidationMatrixFiles();
    expect(ClearCachedUrlAction::run($url))->toBeFalse()
        ->and(invalidationMatrixFiles())->toBe($before)->and($row->fresh())->toBeNull();
    PurgeEdgeCacheAction::assertPushed();
});

it('preserves recorded encoded-path neighbours when clearing a decoded path', function (): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $target = 'https://example.test/space name?page=2';
    $neighbour = 'https://example.test/space%20name?page=3';
    invalidationMatrixSeed($target, $domain);
    $neighbourRow = invalidationMatrixSeed($neighbour, $domain);
    $before = invalidationMatrixFiles();
    $beforeRows = invalidationMatrixRows();
    $expected = [];
    foreach ([false, true] as $error) {
        $file = resolve(HtmlCachePathResolver::class)->pathForRequestUrl($neighbour, $domain, error: $error);
        $expected[$file] = $before[$file];
        $expected[$file . PageCache::FRAGMENT_METADATA_EXTENSION] = $before[$file . PageCache::FRAGMENT_METADATA_EXTENSION];
    }
    ksort($expected);
    expect(ClearCachedUrlAction::run($target, $domain))->toBeTrue()
        ->and(invalidationMatrixFiles())->toBe($expected)
        ->and(invalidationMatrixRows())->toBe([$neighbourRow->id => $beforeRows[$neighbourRow->id]]);
});
