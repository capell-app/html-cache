<?php

declare(strict_types=1);

use Capell\Core\Contracts\Rendering\RenderHookExtensionInterface;
use Capell\Core\Data\Rendering\RenderHookContext;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Translation;
use Capell\Frontend\Actions\Performance\RecordExtensionRenderContributionAction;
use Capell\Frontend\Data\RenderHookContributionData;
use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Support\Render\RenderHookRegistry;
use Capell\HtmlCache\Actions\ClaimStaleCachedUrlAction;
use Capell\HtmlCache\Actions\ExpireOverdueStaleCachedUrlsAction;
use Capell\HtmlCache\Actions\MarkAllCachedUrlsStaleAction;
use Capell\HtmlCache\Actions\MarkCachedUrlStaleAction;
use Capell\HtmlCache\Actions\ProcessStaleHtmlCacheAction;
use Capell\HtmlCache\Actions\PurgeEdgeCacheAction;
use Capell\HtmlCache\Actions\RefreshCachedUrlAtomicallyAction;
use Capell\HtmlCache\Actions\RefreshOriginStaleCachedUrlAction;
use Capell\HtmlCache\Data\EdgeCachePurgeData;
use Capell\HtmlCache\Enums\HtmlCacheEligibilityReason;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Models\StaleCachedUrl;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Capell\HtmlCache\Support\Cache\HtmlCachePublicationGuard;
use Capell\HtmlCache\Support\Cache\HtmlCacheStore;
use Capell\HtmlCache\Support\Cache\HtmlFrontendOutputCacheInvalidator;
use Capell\HtmlCache\Support\Cache\PageCache;
use Capell\HtmlCache\Support\Cache\RefreshWorkerHeartbeat;
use Capell\HtmlCache\Support\Cache\StatelessPaginationRequest;
use Capell\HtmlCache\Support\StaticSite\StaticSiteExtensionRegistry;
use Capell\HtmlCache\Support\StaticSite\StaticSiteGenerator;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

require_once __DIR__ . '/../Support/CachedModelUrlsTestSupport.php';

uses(HtmlCacheTestCase::class);

function existingStaleCacheDomain(): SiteDomain
{
    // The refresh scenarios start with an existing mount. Creating it quietly avoids draining
    // the setup invalidations while leaving the real observers active during the test.
    return SiteDomain::factory()->createQuietly([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => null,
    ]);
}

function staleCacheOriginRoute(string $uri, callable $handler): Illuminate\Routing\Route
{
    return Route::get($uri, $handler)->middleware(HtmlCacheMiddleware::class);
}

it('keeps cached files available while a full manual clear queues refreshes', function (): void {
    Storage::fake('page_cache');

    $siteDomain = existingStaleCacheDomain();
    $page = Page::factory()
        ->recycle($siteDomain->site)
        ->withTranslations()
        ->create();
    $url = 'https://example.test/about';
    $cachePath = resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $siteDomain);

    Storage::disk('page_cache')->put($cachePath, 'old cached page');
    CachedModelUrl::query()->create([
        'url' => $url,
        'url_hash' => CachedModelUrl::hashUrl($url),
        'path' => '/about',
        'site_id' => $siteDomain->site_id,
        'site_domain_id' => $siteDomain->getKey(),
        'language_id' => $siteDomain->language_id,
        'cacheable_type' => $page->getMorphClass(),
        'cacheable_id' => $page->getKey(),
        'cached_at' => now(),
        'last_seen_at' => now(),
    ]);

    capell_artisan('capell:html-cache:clear')
        ->expectsOutput('Marked 1 URL(s) stale. Nothing has been regenerated yet; the old HTML is still being served.')
        ->expectsOutput('Run capell:html-cache:process-stale (or pass --process) to regenerate them.')
        ->assertSuccessful();

    expect(Storage::disk('page_cache')->exists($cachePath))->toBeTrue()
        ->and(StaleCachedUrl::query()->where('url', $url)->where('reason', 'manual_clear')->exists())->toBeTrue();
});

it('processes the stale queue only when explicitly requested', function (): void {
    Storage::fake('page_cache');

    capell_artisan('capell:html-cache:clear', ['--process' => true])
        ->expectsOutput('Marked 0 URL(s) stale. Nothing has been regenerated yet; the old HTML is still being served.')
        ->expectsOutput(__('capell-html-cache::cache.refresh_summary', ['succeeded' => 0, 'failed' => 0, 'deferred' => 0, 'not_applicable' => 0, 'attempted' => 0]))
        ->doesntExpectOutput('Run capell:html-cache:process-stale (or pass --process) to regenerate them.')
        ->assertSuccessful();
});

it('marks indexed translation urls stale in scheduled mode', function (): void {
    Storage::fake('page_cache');
    config()->set('capell-html-cache.invalidation.mode', 'scheduled');

    [$siteDomain, $page, $translation] = EloquentModel::withoutEvents(function (): array {
        $siteDomain = SiteDomain::factory()->create([
            'scheme' => 'https',
            'domain' => 'example.test',
            'path' => null,
        ]);
        $page = Page::factory()
            ->recycle($siteDomain->site)
            ->withTranslations()
            ->create();

        return [
            $siteDomain,
            $page,
            $page->translations()->where('language_id', $siteDomain->language_id)->first(),
        ];
    });
    expect($translation)->toBeInstanceOf(Translation::class);

    $url = 'https://example.test/about';
    $cachePath = resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $siteDomain);

    Storage::disk('page_cache')->put($cachePath, 'old cached page');
    CachedModelUrl::query()->create([
        'url' => $url,
        'url_hash' => CachedModelUrl::hashUrl($url),
        'path' => '/about',
        'site_id' => $siteDomain->site_id,
        'site_domain_id' => $siteDomain->getKey(),
        'language_id' => $siteDomain->language_id,
        'cacheable_type' => $translation->getMorphClass(),
        'cacheable_id' => $translation->getKey(),
        'cached_at' => now(),
        'last_seen_at' => now(),
    ]);

    $translation->update(['title' => 'Updated page title']);

    expect(Storage::disk('page_cache')->exists($cachePath))->toBeTrue()
        ->and(StaleCachedUrl::query()->where('url', $url)->first())
        ->not->toBeNull()
        ->status->toBe(StaleCachedUrl::STATUS_PENDING)
        ->cache_path->toBe($cachePath);
});

it('marks manually requested cached URLs once per cache target and ignores empty requests', function (): void {
    Storage::fake('page_cache');

    $siteDomain = existingStaleCacheDomain();
    $page = Page::factory()
        ->recycle($siteDomain->site)
        ->withTranslations()
        ->create();
    $url = 'https://example.test/duplicate';
    $cachePath = resolve(HtmlCachePathResolver::class)->pathForUrl('/duplicate', $siteDomain);

    foreach ([1, 2] as $cacheableId) {
        CachedModelUrl::query()->create([
            'url' => $url,
            'url_hash' => CachedModelUrl::hashUrl($url),
            'path' => '/duplicate',
            'site_id' => $siteDomain->site_id,
            'site_domain_id' => $siteDomain->getKey(),
            'language_id' => $siteDomain->language_id,
            'cacheable_type' => $page->getMorphClass(),
            'cacheable_id' => $cacheableId,
            'cached_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    $directCachedUrl = CachedModelUrl::query()->firstOrFail();

    expect(MarkCachedUrlStaleAction::run('', 'manual'))->toBe(0)
        ->and(MarkCachedUrlStaleAction::run($url, 'manual'))->toBe(1)
        ->and(MarkCachedUrlStaleAction::run($directCachedUrl, 'direct'))->toBe(1)
        ->and(StaleCachedUrl::query()->where('url', $url)->count())->toBe(1)
        ->and(StaleCachedUrl::query()->where('url', $url)->first()?->cache_path)->toBe($cachePath)
        ->and(StaleCachedUrl::query()->where('url', $url)->first()?->reason)->toBe('direct');
});

it('does not enqueue configured bypass URLs and retires already queued ones as not applicable', function (): void {
    Storage::fake('page_cache');
    config()->set('capell-html-cache.enabled', true);

    existingStaleCacheDomain();
    $url = 'https://example.test/account/profile';

    config()->set('capell-html-cache.bypass.paths', ['/account/*']);

    expect(MarkCachedUrlStaleAction::run($url, 'manual'))->toBe(0)
        ->and(StaleCachedUrl::query()->where('url', $url)->exists())->toBeFalse();

    config()->set('capell-html-cache.bypass.paths', []);
    expect(MarkCachedUrlStaleAction::run($url, 'manual'))->toBe(1);

    config()->set('capell-html-cache.bypass.paths', ['/account/*']);
    staleCacheOriginRoute('/account/profile', fn (): mixed => response('private page', 200, ['Content-Type' => 'text/html']));

    $staleCachedUrl = StaleCachedUrl::query()->where('url', $url)->firstOrFail();

    expect(ProcessStaleHtmlCacheAction::run(1))->toHaveProperties(['attempted' => 1, 'succeeded' => 0, 'failed' => 0, 'notApplicable' => 1])
        ->and($staleCachedUrl->refresh()->status)->toBe(StaleCachedUrl::STATUS_NOT_APPLICABLE)
        ->and($staleCachedUrl->isTerminal())->toBeTrue()
        ->and($staleCachedUrl->last_error)->toContain('Reason: configured_bypass_rule')
        ->and(ProcessStaleHtmlCacheAction::run(1)->attempted)->toBe(0);
});

it('clears cached HTML when the site domain changes', function (string $mode, bool $delete): void {
    Storage::fake('page_cache');
    config()->set('capell-html-cache.invalidation.mode', $mode);

    $siteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => null,
    ]);
    $page = Page::factory()
        ->recycle($siteDomain->site)
        ->withTranslations()
        ->create();
    $url = 'https://example.test/about';
    $cachePath = resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $siteDomain);

    Storage::disk('page_cache')->put($cachePath, 'old cached page');
    CachedModelUrl::query()->create([
        'url' => $url,
        'url_hash' => CachedModelUrl::hashUrl($url),
        'path' => '/about',
        'site_id' => $siteDomain->site_id,
        'site_domain_id' => $siteDomain->getKey(),
        'language_id' => $siteDomain->language_id,
        'cacheable_type' => $page->getMorphClass(),
        'cacheable_id' => $page->getKey(),
        'cached_at' => now(),
        'last_seen_at' => now(),
    ]);

    if ($delete) {
        $siteDomain->delete();
    } else {
        $siteDomain->update(['domain' => 'new-example.test']);
    }

    if ($mode === 'instant') {
        app()->terminate();
    }

    expect(Storage::disk('page_cache')->exists($cachePath))->toBeFalse()
        ->and(CachedModelUrl::query()->where('url', $url)->exists())->toBeFalse()
        ->and(StaleCachedUrl::query()->where('url', $url)->exists())->toBeFalse();
})->with([
    'rename in instant mode' => ['instant', false],
    'rename in scheduled mode' => ['scheduled', false],
    'delete in scheduled mode' => ['scheduled', true],
]);

it('atomically refreshes stale cached html and marks the stale row processed', function (): void {
    Storage::fake('page_cache');

    $siteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => null,
    ]);
    $page = Page::factory()
        ->recycle($siteDomain->site)
        ->withTranslations()
        ->create();
    $url = 'https://example.test/about';
    $cachePath = resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $siteDomain);

    bindHtmlCacheFrontendContext($page);
    Storage::disk('page_cache')->put($cachePath, 'old cached page');
    staleCacheOriginRoute('/about', fn (): mixed => response('fresh cached page', 200, ['Content-Type' => 'text/html']));

    $staleCachedUrl = StaleCachedUrl::query()->create([
        'url' => $url,
        'url_hash' => CachedModelUrl::hashUrl($url),
        'path' => '/about',
        'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl($url), $siteDomain->site_id, $siteDomain->getKey(), '/about'),
        'site_id' => $siteDomain->site_id,
        'site_domain_id' => $siteDomain->getKey(),
        'language_id' => $siteDomain->language_id,
        'cache_path' => $cachePath,
        'error_cache_path' => resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $siteDomain, error: true),
        'reason' => 'test',
        'status' => StaleCachedUrl::STATUS_PENDING,
    ]);

    expect(ProcessStaleHtmlCacheAction::run(1)->attempted)->toBe(1)
        ->and(Storage::disk('page_cache')->get($cachePath))->toBe('fresh cached page')
        ->and($staleCachedUrl->refresh()->status)->toBe(StaleCachedUrl::STATUS_PROCESSED)
        ->and($staleCachedUrl->processed_at)->not->toBeNull();
});

it('retires redirect responses as not applicable instead of exhausting the stale row', function (): void {
    Storage::fake('page_cache');
    config()->set('capell-html-cache.enabled', true);

    $siteDomain = existingStaleCacheDomain();
    staleCacheOriginRoute('/renamed', fn (): mixed => redirect('/new-location', Response::HTTP_MOVED_PERMANENTLY));

    $staleCachedUrl = staleCacheRowForCoverage($siteDomain, '/renamed', StaleCachedUrl::STATUS_PENDING);

    $result = ProcessStaleHtmlCacheAction::run(1);
    expect($result->failed)->toBe(0, (string) $staleCachedUrl->refresh()->last_error);
    expect($result)->toHaveProperties(['attempted' => 1, 'succeeded' => 0, 'failed' => 0, 'notApplicable' => 1])
        ->and($staleCachedUrl->refresh()->status)->toBe(StaleCachedUrl::STATUS_NOT_APPLICABLE)
        ->and($staleCachedUrl->isTerminal())->toBeTrue()
        ->and($staleCachedUrl->last_error)->toContain('Reason: redirect_url')
        ->and(ProcessStaleHtmlCacheAction::run(1)->attempted)->toBe(0);
});

it('refreshes stale HTML through middleware with no configured Vary headers', function (): void {
    Storage::fake('page_cache');
    config()->set('capell-html-cache.http_cache.browser_max_age', 300);
    config()->set('capell-html-cache.http_cache.shared_max_age', 1800);
    config()->set('capell-html-cache.cache_vary_headers', []);

    $siteDomain = existingStaleCacheDomain();
    $page = Page::factory()
        ->recycle($siteDomain->site)
        ->withTranslations()
        ->create();
    $url = 'https://example.test/about';
    $cachePath = resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $siteDomain);

    bindHtmlCacheFrontendContext($page);
    Storage::disk('page_cache')->put($cachePath, 'old cached page');
    staleCacheOriginRoute('/about', fn (): mixed => response('fresh cached page', 200, ['Content-Type' => 'text/html; charset=utf-8']));

    $staleCachedUrl = StaleCachedUrl::query()->create([
        'url' => $url,
        'url_hash' => CachedModelUrl::hashUrl($url),
        'path' => '/about',
        'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl($url), $siteDomain->site_id, $siteDomain->id, '/about'),
        'site_id' => $siteDomain->site_id,
        'site_domain_id' => $siteDomain->getKey(),
        'language_id' => $siteDomain->language_id,
        'cache_path' => $cachePath,
        'error_cache_path' => resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $siteDomain, error: true),
        'reason' => 'test',
        'status' => StaleCachedUrl::STATUS_PENDING,
    ]);

    expect(ProcessStaleHtmlCacheAction::run(1)->attempted)->toBe(1)
        ->and(Storage::disk('page_cache')->get($cachePath))->toBe('fresh cached page')
        ->and($staleCachedUrl->refresh()->last_error)->toBeNull()
        ->and($staleCachedUrl->status)->toBe(StaleCachedUrl::STATUS_PROCESSED)
        ->and($staleCachedUrl->processed_at)->not->toBeNull();
});

it('keeps the previous cached html when stale refresh fails', function (): void {
    Storage::fake('page_cache');

    $siteDomain = existingStaleCacheDomain();
    $url = 'https://example.test/about';
    $cachePath = resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $siteDomain);

    Storage::disk('page_cache')->put($cachePath, 'old cached page');
    staleCacheOriginRoute('/about', fn (): mixed => response('broken', 500, ['Content-Type' => 'text/html']));

    $staleCachedUrl = StaleCachedUrl::query()->create([
        'url' => $url,
        'url_hash' => CachedModelUrl::hashUrl($url),
        'path' => '/about',
        'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl($url), $siteDomain->site_id, $siteDomain->id, '/about'),
        'site_id' => $siteDomain->site_id,
        'site_domain_id' => $siteDomain->getKey(),
        'language_id' => $siteDomain->language_id,
        'cache_path' => $cachePath,
        'error_cache_path' => resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $siteDomain, error: true),
        'reason' => 'test',
        'status' => StaleCachedUrl::STATUS_PENDING,
    ]);

    expect(ProcessStaleHtmlCacheAction::run(1)->attempted)->toBe(1)
        ->and(Storage::disk('page_cache')->get($cachePath))->toBe('old cached page')
        ->and($staleCachedUrl->refresh()->status)->toBe(StaleCachedUrl::STATUS_FAILED)
        ->and($staleCachedUrl->last_error)->toContain('response status was 500');
});

it('rejects stale refresh cache paths outside the page cache disk root', function (string $field): void {
    Storage::fake('page_cache');

    $siteDomain = existingStaleCacheDomain();
    $page = Page::factory()
        ->recycle($siteDomain->site)
        ->withTranslations()
        ->create();

    bindHtmlCacheFrontendContext($page);
    $renders = 0;
    staleCacheOriginRoute('/about', function () use (&$renders): Response {
        $renders++;

        return response('fresh cached page', 200, ['Content-Type' => 'text/html', 'Cache-Control' => 'public']);
    });

    $unsafePaths = [
        'traversal' => '../outside.html',
        'backslash traversal' => '..\\outside.html',
        'absolute' => '/outside.html',
        'drive absolute' => 'C:/outside.html',
        'drive relative' => 'C:outside.html',
        'NUL' => "outside\0.html",
        'current directory only' => '.',
    ];
    foreach ($unsafePaths as $scenario => $unsafePath) {
        $staleCachedUrl = staleCacheRowForCoverage($siteDomain, '/about', StaleCachedUrl::STATUS_PENDING, [
            $field => $unsafePath,
        ]);

        expect(ProcessStaleHtmlCacheAction::run(1)->attempted)->toBe(1, $scenario)
            ->and($renders)->toBe(0, $scenario)
            ->and(resolve(HtmlCacheStore::class)->path('../outside.html'))->toBeNull()
            ->and(htmlCachePageEntries())->toBe([], $scenario)
            ->and($staleCachedUrl->refresh()->status)->toBe(StaleCachedUrl::STATUS_FAILED, $scenario)
            ->and($staleCachedUrl->last_error)->toContain('cache path was invalid');

        $staleCachedUrl->delete();
    }
})->with(['cache_path', 'error_cache_path']);

it('rejects stale refresh stored symlink paths before origin rendering', function (string $alias): void {
    Storage::fake('page_cache');
    $domain = existingStaleCacheDomain();
    bindHtmlCacheFrontendContext(Page::factory()->recycle($domain->site)->withTranslations()->create());
    $disk = Storage::disk('page_cache');
    $outside = sys_get_temp_dir() . '/html-cache-stale-path-' . bin2hex(random_bytes(8));
    File::ensureDirectoryExists($outside);
    File::put($outside . '/page.html', 'outside bytes');
    $link = $disk->path('unsafe-link');
    $target = match ($alias) {
        'directory' => $outside,
        'file' => $outside . '/page.html',
        'dangling' => $outside . '/missing.html',
        default => throw new LogicException('Unknown symlink alias'),
    };
    $renders = 0;
    staleCacheOriginRoute('/about', function () use (&$renders): Response {
        $renders++;

        return response('fresh cached page', 200, ['Content-Type' => 'text/html']);
    });

    try {
        expect(symlink($target, $link))->toBeTrue();

        foreach (['cache_path', 'error_cache_path'] as $field) {
            $row = staleCacheRowForCoverage($domain, '/about', StaleCachedUrl::STATUS_PENDING, [
                $field => $alias === 'directory' ? 'unsafe-link/page.html' : 'unsafe-link',
            ]);
            $result = ProcessStaleHtmlCacheAction::run(1);
            expect($result)->toHaveProperties(['attempted' => 1, 'succeeded' => 0, 'failed' => 1, 'notApplicable' => 0])
                ->and($renders)->toBe(0)
                ->and($row->refresh()->status)->toBe(StaleCachedUrl::STATUS_FAILED)
                ->and($row->last_error)->toContain($alias === 'dangling' ? 'Unable to resolve the HTML cache path' : 'outside the cache root')
                ->and($disk->exists(resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $domain)))->toBeFalse()
                ->and(File::get($outside . '/page.html'))->toBe('outside bytes');

            $row->delete();
        }
    } finally {
        if (is_link($link)) {
            unlink($link);
        }

        File::deleteDirectory($outside);
    }
})->with(['directory', 'file', 'dangling']);

it('preserves the retirement failure for historical rows with omitted stored paths', function (?string $missingPath): void {
    Storage::fake('page_cache');
    $domain = existingStaleCacheDomain();
    bindHtmlCacheFrontendContext(Page::factory()->recycle($domain->site)->withTranslations()->create());
    $row = staleCacheRowForCoverage($domain, '/feed', StaleCachedUrl::STATUS_PENDING);
    $path = $row->cache_path;
    assert(is_string($path));
    Storage::disk('page_cache')->put($path, 'old cached page');
    $row->update(['cache_path' => $missingPath, 'error_cache_path' => $missingPath]);
    Route::get('/feed', static fn (): Response => response('<feed/>', 200, ['Content-Type' => 'application/xml', 'Cache-Control' => 'max-age=3600, public']));

    $result = ProcessStaleHtmlCacheAction::run(1);
    expect($result)->toHaveProperties(['attempted' => 1, 'succeeded' => 0, 'failed' => 1, 'notApplicable' => 0])
        ->and(Storage::disk('page_cache')->get($path))->toBe('old cached page')
        ->and($row->refresh()->status)->toBe(StaleCachedUrl::STATUS_FAILED)
        ->and($row->last_error)->toContain('Unable to resolve historical cache paths', 'cache tracking has been retained');
})->with([null, '']);

it('blocks unsafe public html during stale refresh and keeps the old cache file', function (): void {
    Storage::fake('page_cache');
    config()->set('capell-html-cache.enabled', true);

    $siteDomain = existingStaleCacheDomain();
    $url = 'https://example.test/about';
    $cachePath = resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $siteDomain);

    Storage::disk('page_cache')->put($cachePath, 'old cached page');
    staleCacheOriginRoute('/about', fn (): mixed => response('<div data-capell-editor="1"></div>', 200, ['Content-Type' => 'text/html']));

    $staleCachedUrl = StaleCachedUrl::query()->create([
        'url' => $url,
        'url_hash' => CachedModelUrl::hashUrl($url),
        'path' => '/about',
        'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl($url), $siteDomain->site_id, $siteDomain->id, '/about'),
        'site_id' => $siteDomain->site_id,
        'site_domain_id' => $siteDomain->getKey(),
        'language_id' => $siteDomain->language_id,
        'cache_path' => $cachePath,
        'error_cache_path' => resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $siteDomain, error: true),
        'reason' => 'test',
        'status' => StaleCachedUrl::STATUS_PENDING,
    ]);

    ProcessStaleHtmlCacheAction::run(1);

    expect(Storage::disk('page_cache')->get($cachePath))->toBe('old cached page')
        ->and($staleCachedUrl->refresh()->status)->toBe(StaleCachedUrl::STATUS_FAILED)
        ->and($staleCachedUrl->last_error)->toContain('not cacheable', 'Reason: unsafe_public_output');
});

it('records the exact rejected check when response headers look publicly cacheable', function (string $condition, string $expectedReason): void {
    Storage::fake('page_cache');
    config()->set('capell-html-cache.enabled', true);

    $siteDomain = existingStaleCacheDomain();
    $url = 'https://example.test/about';
    $cachePath = resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $siteDomain);

    Storage::disk('page_cache')->put($cachePath, 'old cached page');
    config()->set('capell-html-cache.cache_vary_headers', ['Accept-Encoding']);
    config()->set('capell-html-cache.write_enabled', $condition !== 'writes_disabled');
    config()->set('capell-html-cache.enabled', $condition !== 'disabled');

    $kernel = Mockery::mock(Kernel::class);
    $kernel->shouldReceive('handle')->once()->andReturnUsing(function (Request $request) use ($condition): Response {
        if ($condition === 'session') {
            $request->setLaravelSession(resolve(Session::class));
            $request->session()->put('status', 'private visitor status');
        }

        $origin = response($condition === 'token' ? '<input name="_token" value="private-token">' : '<main>Pricing</main>', 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'max-age=300, public, s-maxage=1800, stale-while-revalidate=86400',
        ]);
        expect($origin->headers->get('Cache-Control'))->toBe('max-age=300, public, s-maxage=1800, stale-while-revalidate=86400');

        return resolve(HtmlCacheMiddleware::class)->handle($request, static fn (): Response => $origin);
    });
    $kernel->shouldReceive('terminate')->once();
    app()->instance(Kernel::class, $kernel);

    $staleCachedUrl = StaleCachedUrl::query()->create([
        'url' => $url,
        'url_hash' => CachedModelUrl::hashUrl($url),
        'path' => '/about',
        'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl($url), $siteDomain->site_id, $siteDomain->id, '/about'),
        'site_id' => $siteDomain->site_id,
        'site_domain_id' => $siteDomain->getKey(),
        'language_id' => $siteDomain->language_id,
        'cache_path' => $cachePath,
        'error_cache_path' => resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $siteDomain, error: true),
        'reason' => 'test',
        'status' => StaleCachedUrl::STATUS_PENDING,
    ]);

    ProcessStaleHtmlCacheAction::run(1);

    expect(Storage::disk('page_cache')->get($cachePath))->toBe('old cached page')
        ->and($staleCachedUrl->refresh()->status)->toBe(StaleCachedUrl::STATUS_FAILED)
        ->and($staleCachedUrl->last_error)->toContain('not cacheable', 'Reason: ' . $expectedReason, 'Vary: [].', 'Cookies: 0.')
        ->not->toContain('private visitor status', 'private-token');
})->with([
    'writes disabled' => ['writes_disabled', 'cache_write_disabled'],
    'cache disabled' => ['disabled', 'cache_disabled'],
    'visitor session state' => ['session', 'session_user_state'],
    'baked session token' => ['token', 'baked_session_token'],
]);

it('uses middleware cacheability rules during stale refresh', function (): void {
    Storage::fake('page_cache');

    $siteDomain = existingStaleCacheDomain();
    $page = Page::factory()
        ->recycle($siteDomain->site)
        ->withTranslations()
        ->create();
    $url = 'https://example.test/about';
    $cachePath = resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $siteDomain);

    bindHtmlCacheFrontendContext($page);
    Storage::disk('page_cache')->put($cachePath, 'old cached page');
    staleCacheOriginRoute('/about', function (): mixed {
        RecordExtensionRenderContributionAction::run(
            packageName: 'vendor/editorial-tools',
            surface: 'frontend',
            contributionType: 'frontend-component',
            contributionClass: 'Vendor\\EditorialTools\\Components\\RelatedStories',
            elapsedMilliseconds: 1.2,
            frontendRenderBudgetMs: 10,
            cacheTags: ['extension:editorial-tools'],
            cacheable: false,
            sensitiveOutput: false,
            variesBy: ['site', 'locale'],
        );

        return response('fresh unsafe extension html', 200, ['Content-Type' => 'text/html']);
    });

    $staleCachedUrl = StaleCachedUrl::query()->create([
        'url' => $url,
        'url_hash' => CachedModelUrl::hashUrl($url),
        'path' => '/about',
        'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl($url), $siteDomain->site_id, $siteDomain->id, '/about'),
        'site_id' => $siteDomain->site_id,
        'site_domain_id' => $siteDomain->getKey(),
        'language_id' => $siteDomain->language_id,
        'cache_path' => $cachePath,
        'error_cache_path' => resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $siteDomain, error: true),
        'reason' => 'test',
        'status' => StaleCachedUrl::STATUS_PENDING,
    ]);

    ProcessStaleHtmlCacheAction::run(1);

    expect(Storage::disk('page_cache')->exists($cachePath))->toBeFalse()
        ->and($staleCachedUrl->refresh()->status)->toBe(StaleCachedUrl::STATUS_NOT_APPLICABLE)
        ->and($staleCachedUrl->isTerminal())->toBeTrue()
        ->and($staleCachedUrl->last_error)->toContain('not cacheable', 'Reason: package_cache_blocking');
});

it('publishes a fragmented stale refresh without treating the assembled response as public', function (): void {
    Storage::fake('page_cache');

    $siteDomain = existingStaleCacheDomain();
    $page = Page::factory()
        ->recycle($siteDomain->site)
        ->withTranslations()
        ->create();
    $url = 'https://example.test/about';
    $cachePath = resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $siteDomain);

    bindHtmlCacheFrontendContext($page);
    Storage::disk('page_cache')->put($cachePath, 'old cached page');

    /** @var RenderHookRegistry<RenderHookContext> $registry */
    $registry = new RenderHookRegistry(app());
    app()->instance(RenderHookRegistry::class, $registry);

    $registry->contribute(RenderHookContributionData::extension(
        location: RenderHookLocation::BodyEnd,
        extension: new class implements RenderHookExtensionInterface
        {
            public function render(RenderHookContext $context): string
            {
                return '<aside>fresh fragment</aside>';
            }
        },
        owner: 'vendor/stale-fragment',
        key: 'fresh-fragment',
        cacheSafe: false,
        fragment: true,
    ));

    staleCacheOriginRoute('/about', fn (): Response => response(
        '<main>fresh shell' . resolve(RenderHookRegistry::class)->renderAll(RenderHookLocation::BodyEnd) . '</main>',
        200,
        ['Content-Type' => 'text/html; charset=utf-8'],
    ));

    $staleCachedUrl = StaleCachedUrl::query()->create([
        'url' => $url,
        'url_hash' => CachedModelUrl::hashUrl($url),
        'path' => '/about',
        'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl($url), $siteDomain->site_id, $siteDomain->id, '/about'),
        'site_id' => $siteDomain->site_id,
        'site_domain_id' => $siteDomain->getKey(),
        'language_id' => $siteDomain->language_id,
        'cache_path' => $cachePath,
        'error_cache_path' => resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $siteDomain, error: true),
        'reason' => 'test',
        'status' => StaleCachedUrl::STATUS_PENDING,
    ]);

    expect(ProcessStaleHtmlCacheAction::run(1, suppressInlineEdgePurge: true)->attempted)->toBe(1)
        ->and(Storage::disk('page_cache')->get($cachePath))->toBe('<main>fresh shell</main>')
        ->and(Storage::disk('page_cache')->exists($cachePath . '.fragments.json'))->toBeTrue()
        ->and($staleCachedUrl->refresh()->status)->toBe(StaleCachedUrl::STATUS_PROCESSED)
        ->and($staleCachedUrl->last_error)->toBeNull();
});

it('deletes obsolete stale cache files and indexed urls when the domain no longer resolves', function (bool $suppressInlineEdgePurge): void {
    Storage::fake('page_cache');
    config()->set('capell-html-cache.enabled', true);
    Queue::fake();

    $page = Page::factory()->withTranslations()->create();
    $url = 'https://obsolete.example.test/about';
    $urlHash = CachedModelUrl::hashUrl($url);
    $cachePath = 'https.obsolete.example.test/about.html';
    $errorCachePath = 'https.obsolete.example.test/about.404.html';

    Storage::disk('page_cache')->put($cachePath, 'old cached page');
    Storage::disk('page_cache')->put($errorCachePath, 'old missing page');
    CachedModelUrl::query()->create([
        'url' => $url,
        'url_hash' => $urlHash,
        'path' => '/about',
        'site_id' => null,
        'site_domain_id' => null,
        'language_id' => null,
        'cacheable_type' => $page->getMorphClass(),
        'cacheable_id' => $page->getKey(),
        'cached_at' => now(),
        'last_seen_at' => now(),
    ]);
    $staleCachedUrl = StaleCachedUrl::query()->create([
        'url' => $url,
        'url_hash' => $urlHash,
        'path' => '/about',
        'stale_key' => StaleCachedUrl::staleKey($urlHash, null, null, '/about'),
        'site_id' => null,
        'site_domain_id' => null,
        'language_id' => null,
        'cache_path' => $cachePath,
        'error_cache_path' => $errorCachePath,
        'reason' => 'domain_removed',
        'status' => StaleCachedUrl::STATUS_PROCESSING,
        'claim_token' => 'current-claim',
        'attempts' => 1,
    ]);

    RefreshCachedUrlAtomicallyAction::run($staleCachedUrl, $suppressInlineEdgePurge);

    expect(Storage::disk('page_cache')->exists($cachePath))->toBeFalse()
        ->and(Storage::disk('page_cache')->exists($errorCachePath))->toBeFalse()
        ->and(CachedModelUrl::query()->where('url_hash', $urlHash)->exists())->toBeFalse();

    if ($suppressInlineEdgePurge) {
        PurgeEdgeCacheAction::assertNotPushed();
    } else {
        PurgeEdgeCacheAction::assertPushed();
    }
})->with(['default purge' => false, 'suppressed purge' => true]);

it('retires a domain-less stale url whose row carries no cache paths instead of failing every run', function (): void {
    // Live 2026-11-22: an http:// URL of an https-only site domain was marked stale with no
    // owner and no paths, so every scheduled run failed it until maximum-age expiry.
    Storage::fake('page_cache');
    config()->set('capell-html-cache.enabled', true);
    Queue::fake();
    SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);

    $page = Page::factory()->withTranslations()->create();
    $url = 'http://example.test/marketplace/extensions/studio';
    $urlHash = CachedModelUrl::hashUrl($url);
    $cachePath = resolve(HtmlCachePathResolver::class)->pathForRequestUrl($url);
    Storage::disk('page_cache')->put($cachePath, 'old cached page');
    Storage::disk('page_cache')->put('https.example.test/marketplace/extensions/studio.html', 'canonical page');
    CachedModelUrl::query()->create([
        'url' => $url, 'url_hash' => $urlHash, 'path' => '/marketplace/extensions/studio',
        'site_id' => null, 'site_domain_id' => null, 'language_id' => null,
        'cacheable_type' => $page->getMorphClass(), 'cacheable_id' => $page->getKey(),
        'cached_at' => now(), 'last_seen_at' => now(),
    ]);
    $row = StaleCachedUrl::query()->create([
        'url' => $url, 'url_hash' => $urlHash, 'path' => '/marketplace/extensions/studio',
        'stale_key' => StaleCachedUrl::staleKey($urlHash, null, null, '/marketplace/extensions/studio'),
        'site_id' => null, 'site_domain_id' => null, 'language_id' => null,
        'cache_path' => null, 'error_cache_path' => null,
        'reason' => 'frontend_output_changed', 'status' => StaleCachedUrl::STATUS_PENDING,
    ]);

    $result = ProcessStaleHtmlCacheAction::run(1);

    expect($result)->toHaveProperties(['attempted' => 1, 'succeeded' => 1, 'failed' => 0])
        ->and($row->refresh()->status)->toBe(StaleCachedUrl::STATUS_PROCESSED)
        ->and(Storage::disk('page_cache')->exists($cachePath))->toBeFalse()
        ->and(Storage::disk('page_cache')->get('https.example.test/marketplace/extensions/studio.html'))->toBe('canonical page')
        ->and(CachedModelUrl::query()->where('url_hash', $urlHash)->exists())->toBeFalse();
});

it('refreshes missing pages into the error cache', function (): void {
    Storage::fake('page_cache');

    $siteDomain = existingStaleCacheDomain();
    $page = Page::factory()
        ->recycle($siteDomain->site)
        ->withTranslations()
        ->create();
    $url = 'https://example.test/missing';
    $cachePath = resolve(HtmlCachePathResolver::class)->pathForUrl('/missing', $siteDomain);
    $errorCachePath = resolve(HtmlCachePathResolver::class)->pathForUrl('/missing', $siteDomain, error: true);

    bindHtmlCacheFrontendContext($page);
    Storage::disk('page_cache')->put($cachePath, 'old cached page');
    staleCacheOriginRoute('/missing', fn (): mixed => response('fresh missing page', 404, ['Content-Type' => 'text/html']));

    $staleCachedUrl = StaleCachedUrl::query()->create([
        'url' => $url,
        'url_hash' => CachedModelUrl::hashUrl($url),
        'path' => '/missing',
        'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl($url), $siteDomain->site_id, $siteDomain->id, '/missing'),
        'site_id' => $siteDomain->site_id,
        'site_domain_id' => $siteDomain->getKey(),
        'language_id' => $siteDomain->language_id,
        'cache_path' => $cachePath,
        'error_cache_path' => $errorCachePath,
        'reason' => 'test',
        'status' => StaleCachedUrl::STATUS_PENDING,
    ]);

    expect(ProcessStaleHtmlCacheAction::run(1)->attempted)->toBe(1)
        ->and(Storage::disk('page_cache')->get($errorCachePath))->toBe('fresh missing page')
        ->and(Storage::disk('page_cache')->exists($cachePath))->toBeFalse()
        ->and($staleCachedUrl->refresh()->status)->toBe(StaleCachedUrl::STATUS_PROCESSED);
});

it('removes every artefact of the previous status when a refresh changes status', function (int $status, string $fresh, bool $toMissing, bool $historicalRow): void {
    Storage::fake('page_cache');

    $siteDomain = existingStaleCacheDomain();
    $page = Page::factory()
        ->recycle($siteDomain->site)
        ->withTranslations()
        ->create();
    $url = 'https://example.test/index';
    $paths = resolve(HtmlCachePathResolver::class);
    $currentPath = $paths->pathForUrl('/index', $siteDomain);
    $currentErrorPath = $paths->pathForUrl('/index', $siteDomain, error: true);
    $historicalPath = $paths->rootForRequest(Request::create($url), $siteDomain) . '/index.html';
    // Rows written before the key change store the historical name, not the current key.
    $cachePath = $historicalRow ? $historicalPath : $currentPath;
    $errorCachePath = $historicalRow ? substr($historicalPath, 0, -5) . '.404.html' : $currentErrorPath;
    $fragmentRequest = Request::create($url);
    $fragmentRequest->headers->set(StatelessPaginationRequest::FRAGMENT_HEADER, 'hero');

    $fragmentPath = $paths->pathForRequestUrl($fragmentRequest, $siteDomain);
    $fragmentErrorPath = $paths->pathForRequestUrl($fragmentRequest, $siteDomain, error: true);

    bindHtmlCacheFrontendContext($page);
    $stale = $toMissing
        ? array_values(array_unique([$currentPath, $fragmentPath, $historicalPath]))
        : array_values(array_unique([$currentErrorPath, $fragmentErrorPath, $errorCachePath]));
    foreach ($stale as $file) {
        Storage::disk('page_cache')->put($file, 'previous status');
    }

    staleCacheOriginRoute('/index', fn (): mixed => response($fresh, $status, ['Content-Type' => 'text/html']));

    $staleCachedUrl = StaleCachedUrl::query()->create([
        'url' => $url,
        'url_hash' => CachedModelUrl::hashUrl($url),
        'path' => '/index',
        'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl($url), $siteDomain->site_id, $siteDomain->id, '/index'),
        'site_id' => $siteDomain->site_id,
        'site_domain_id' => $siteDomain->getKey(),
        'language_id' => $siteDomain->language_id,
        'cache_path' => $cachePath,
        'error_cache_path' => $errorCachePath,
        'reason' => 'test',
        'status' => StaleCachedUrl::STATUS_PENDING,
    ]);

    expect(ProcessStaleHtmlCacheAction::run(1)->attempted)->toBe(1)
        ->and($staleCachedUrl->refresh()->status)->toBe(StaleCachedUrl::STATUS_PROCESSED)
        ->and(Storage::disk('page_cache')->get($toMissing ? $currentErrorPath : $currentPath))->toBe($fresh);

    foreach ($stale as $file) {
        expect(Storage::disk('page_cache')->exists($file))->toBeFalse();
    }
})->with([
    'page removed (200 to 404)' => [404, 'fresh missing page', true, false],
    'page restored (404 to 200)' => [200, 'fresh restored page', false, false],
    'historical row removed (200 to 404)' => [404, 'fresh missing page', true, true],
    'historical row restored (404 to 200)' => [200, 'fresh restored page', false, true],
]);

it('never restores a page that a concurrent clear removed during a status change', function (): void {
    Storage::fake('page_cache');

    $siteDomain = existingStaleCacheDomain();
    $page = Page::factory()
        ->recycle($siteDomain->site)
        ->withTranslations()
        ->create();
    $url = 'https://example.test/index';
    $paths = resolve(HtmlCachePathResolver::class);
    $cachePath = $paths->pathForUrl('/index', $siteDomain);
    $errorCachePath = $paths->pathForUrl('/index', $siteDomain, error: true);

    bindHtmlCacheFrontendContext($page);
    Storage::disk('page_cache')->put($cachePath, 'previous status');
    staleCacheOriginRoute('/index', fn (): mixed => response('fresh missing page', 404, ['Content-Type' => 'text/html']));

    // A clear lands while the refresh is evicting: the freshly published 404 goes first.
    $fake = Storage::disk('page_cache');
    $disk = new class($fake->getDriver(), $fake->getAdapter(), $fake->getConfig()) extends FilesystemAdapter
    {
        public ?Closure $beforeFirstDelete = null;

        /** @param  string|array<int, string>  $paths */
        #[Override]
        public function delete($paths): bool
        {
            $before = $this->beforeFirstDelete;
            $this->beforeFirstDelete = null;
            if ($before instanceof Closure) {
                $before();
            }

            return parent::delete($paths);
        }
    };
    $disk->beforeFirstDelete = static fn (): bool => $fake->delete($errorCachePath);
    Storage::set('page_cache', $disk);
    app()->forgetInstance(HtmlCacheStore::class);

    StaleCachedUrl::query()->create([
        'url' => $url,
        'url_hash' => CachedModelUrl::hashUrl($url),
        'path' => '/index',
        'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl($url), $siteDomain->site_id, $siteDomain->id, '/index'),
        'site_id' => $siteDomain->site_id,
        'site_domain_id' => $siteDomain->getKey(),
        'language_id' => $siteDomain->language_id,
        'cache_path' => $cachePath,
        'error_cache_path' => $errorCachePath,
        'reason' => 'test',
        'status' => StaleCachedUrl::STATUS_PENDING,
    ]);

    expect(ProcessStaleHtmlCacheAction::run(1)->attempted)->toBe(1)
        ->and(Storage::disk('page_cache')->exists($errorCachePath))->toBeFalse()
        ->and(Storage::disk('page_cache')->exists($cachePath))->toBeFalse();
});

it('preserves the old cache file when stale refresh cache writes are disabled', function (): void {
    Storage::fake('page_cache');
    config()->set('capell-html-cache.write_enabled', false);

    $siteDomain = existingStaleCacheDomain();
    $page = Page::factory()
        ->recycle($siteDomain->site)
        ->withTranslations()
        ->create();
    $url = 'https://example.test/write-disabled';
    $cachePath = resolve(HtmlCachePathResolver::class)->pathForUrl('/write-disabled', $siteDomain);

    bindHtmlCacheFrontendContext($page);
    Storage::disk('page_cache')->put($cachePath, 'old cached page');
    staleCacheOriginRoute('/write-disabled', fn (): mixed => response('fresh cached page', 200, ['Content-Type' => 'text/html']));

    $staleCachedUrl = StaleCachedUrl::query()->create([
        'url' => $url,
        'url_hash' => CachedModelUrl::hashUrl($url),
        'path' => '/write-disabled',
        'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl($url), $siteDomain->site_id, $siteDomain->id, '/write-disabled'),
        'site_id' => $siteDomain->site_id,
        'site_domain_id' => $siteDomain->getKey(),
        'language_id' => $siteDomain->language_id,
        'cache_path' => $cachePath,
        'error_cache_path' => resolve(HtmlCachePathResolver::class)->pathForUrl('/write-disabled', $siteDomain, error: true),
        'reason' => 'test',
        'status' => StaleCachedUrl::STATUS_PENDING,
    ]);

    ProcessStaleHtmlCacheAction::run(1);

    expect(Storage::disk('page_cache')->get($cachePath))->toBe('old cached page')
        ->and($staleCachedUrl->refresh()->status)->toBe(StaleCachedUrl::STATUS_FAILED)
        ->and($staleCachedUrl->last_error)->toContain('not cacheable');
});

it('retries failed stale cache rows after the retry backoff', function (): void {
    Storage::fake('page_cache');
    config()->set('capell-html-cache.invalidation.retry_backoff_minutes', 5);

    $siteDomain = existingStaleCacheDomain();
    $page = Page::factory()
        ->recycle($siteDomain->site)
        ->withTranslations()
        ->create();
    $url = 'https://example.test/about';
    $cachePath = resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $siteDomain);

    bindHtmlCacheFrontendContext($page);
    Storage::disk('page_cache')->put($cachePath, 'old cached page');
    staleCacheOriginRoute('/about', fn (): mixed => response('fresh cached page', 200, ['Content-Type' => 'text/html']));

    $staleCachedUrl = StaleCachedUrl::query()->create([
        'url' => $url,
        'url_hash' => CachedModelUrl::hashUrl($url),
        'path' => '/about',
        'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl($url), $siteDomain->site_id, $siteDomain->id, '/about'),
        'site_id' => $siteDomain->site_id,
        'site_domain_id' => $siteDomain->getKey(),
        'language_id' => $siteDomain->language_id,
        'cache_path' => $cachePath,
        'error_cache_path' => resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $siteDomain, error: true),
        'reason' => 'test',
        'status' => StaleCachedUrl::STATUS_FAILED,
        'attempts' => 1,
        'failed_at' => now()->subMinutes(6),
        'last_error' => 'temporary failure',
    ]);

    expect(ProcessStaleHtmlCacheAction::run(1)->attempted)->toBe(1)
        ->and(Storage::disk('page_cache')->get($cachePath))->toBe('fresh cached page')
        ->and($staleCachedUrl->refresh()->status)->toBe(StaleCachedUrl::STATUS_PROCESSED)
        ->and($staleCachedUrl->attempts)->toBe(0);
});

it('does not claim actively processing stale cache rows', function (): void {
    Storage::fake('page_cache');

    $siteDomain = existingStaleCacheDomain();
    $page = Page::factory()
        ->recycle($siteDomain->site)
        ->withTranslations()
        ->create();
    $url = 'https://example.test/about';
    $cachePath = resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $siteDomain);

    bindHtmlCacheFrontendContext($page);
    Storage::disk('page_cache')->put($cachePath, 'old cached page');
    staleCacheOriginRoute('/about', fn (): mixed => response('fresh cached page', 200, ['Content-Type' => 'text/html']));

    $staleCachedUrl = StaleCachedUrl::query()->create([
        'url' => $url,
        'url_hash' => CachedModelUrl::hashUrl($url),
        'path' => '/about',
        'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl($url), $siteDomain->site_id, $siteDomain->id, '/about'),
        'site_id' => $siteDomain->site_id,
        'site_domain_id' => $siteDomain->getKey(),
        'language_id' => $siteDomain->language_id,
        'cache_path' => $cachePath,
        'error_cache_path' => resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $siteDomain, error: true),
        'reason' => 'test',
        'status' => StaleCachedUrl::STATUS_PROCESSING,
        'attempts' => 1,
    ]);

    expect(ProcessStaleHtmlCacheAction::run(1)->attempted)->toBe(0)
        ->and(Storage::disk('page_cache')->get($cachePath))->toBe('old cached page')
        ->and($staleCachedUrl->refresh()->status)->toBe(StaleCachedUrl::STATUS_PROCESSING)
        ->and($staleCachedUrl->attempts)->toBe(1);
});

it('keeps a new stale mark pending when a model changes during stale refresh', function (): void {
    Storage::fake('page_cache');

    $siteDomain = existingStaleCacheDomain();
    $page = Page::factory()
        ->recycle($siteDomain->site)
        ->withTranslations()
        ->create();
    $url = 'https://example.test/about';
    $cachePath = resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $siteDomain);

    bindHtmlCacheFrontendContext($page);
    Storage::disk('page_cache')->put($cachePath, 'old cached page');
    staleCacheOriginRoute('/about', function () use ($url): mixed {
        MarkCachedUrlStaleAction::run($url, 'changed_during_refresh');

        return response('stale in-flight html', 200, ['Content-Type' => 'text/html']);
    });

    $staleCachedUrl = StaleCachedUrl::query()->create([
        'url' => $url,
        'url_hash' => CachedModelUrl::hashUrl($url),
        'path' => '/about',
        'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl($url), $siteDomain->site_id, $siteDomain->id, '/about'),
        'site_id' => $siteDomain->site_id,
        'site_domain_id' => $siteDomain->getKey(),
        'language_id' => $siteDomain->language_id,
        'cache_path' => $cachePath,
        'error_cache_path' => resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $siteDomain, error: true),
        'reason' => 'test',
        'status' => StaleCachedUrl::STATUS_PENDING,
    ]);

    expect(ProcessStaleHtmlCacheAction::run(1)->attempted)->toBe(1)
        ->and(Storage::disk('page_cache')->get($cachePath))->toBe('old cached page')
        ->and($staleCachedUrl->refresh()->status)->toBe(StaleCachedUrl::STATUS_PENDING)
        ->and($staleCachedUrl->claim_token)->toBeNull()
        ->and($staleCachedUrl->reason)->toBe('changed_during_refresh');
});

it('prevents a late stale refresh worker from writing after the row is reclaimed', function (): void {
    Storage::fake('page_cache');

    $siteDomain = existingStaleCacheDomain();
    $page = Page::factory()
        ->recycle($siteDomain->site)
        ->withTranslations()
        ->create();
    $url = 'https://example.test/about';
    $cachePath = resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $siteDomain);

    bindHtmlCacheFrontendContext($page);
    Storage::disk('page_cache')->put($cachePath, 'old cached page');
    staleCacheOriginRoute('/about', fn (): mixed => response('late worker html', 200, ['Content-Type' => 'text/html']));

    $staleCachedUrl = StaleCachedUrl::query()->create([
        'url' => $url,
        'url_hash' => CachedModelUrl::hashUrl($url),
        'path' => '/about',
        'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl($url), $siteDomain->site_id, $siteDomain->id, '/about'),
        'site_id' => $siteDomain->site_id,
        'site_domain_id' => $siteDomain->getKey(),
        'language_id' => $siteDomain->language_id,
        'cache_path' => $cachePath,
        'error_cache_path' => resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $siteDomain, error: true),
        'reason' => 'test',
        'status' => StaleCachedUrl::STATUS_PROCESSING,
        'claim_token' => 'old-claim-token',
        'attempts' => 1,
    ]);
    $lateWorkerStaleCachedUrl = $staleCachedUrl->fresh();

    throw_unless($lateWorkerStaleCachedUrl instanceof StaleCachedUrl, RuntimeException::class, 'Expected stale cached URL row to be available for late worker test.');

    $staleCachedUrl->forceFill(['claim_token' => 'new-claim-token'])->save();

    expect(function () use ($lateWorkerStaleCachedUrl): void {
        RefreshCachedUrlAtomicallyAction::run($lateWorkerStaleCachedUrl);
    })
        ->toThrow(RuntimeException::class);

    expect(Storage::disk('page_cache')->get($cachePath))->toBe('old cached page')
        ->and($staleCachedUrl->refresh()->status)->toBe(StaleCachedUrl::STATUS_PROCESSING)
        ->and($staleCachedUrl->claim_token)->toBe('new-claim-token');
});

it('prevents a late stale refresh worker from deleting cache files after the row is reclaimed', function (): void {
    Storage::fake('page_cache');

    $siteDomain = existingStaleCacheDomain();
    $url = 'https://example.test/about';
    $cachePath = resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $siteDomain);

    Storage::disk('page_cache')->put($cachePath, 'old cached page');
    staleCacheOriginRoute('/about', fn (): mixed => response('missing', 404, ['Content-Type' => 'text/html']));

    $staleCachedUrl = StaleCachedUrl::query()->create([
        'url' => $url,
        'url_hash' => CachedModelUrl::hashUrl($url),
        'path' => '/about',
        'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl($url), $siteDomain->site_id, $siteDomain->id, '/about'),
        'site_id' => $siteDomain->site_id,
        'site_domain_id' => $siteDomain->getKey(),
        'language_id' => $siteDomain->language_id,
        'cache_path' => $cachePath,
        'error_cache_path' => resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $siteDomain, error: true),
        'reason' => 'test',
        'status' => StaleCachedUrl::STATUS_PROCESSING,
        'claim_token' => 'old-claim-token',
        'attempts' => 1,
    ]);
    $lateWorkerStaleCachedUrl = $staleCachedUrl->fresh();

    throw_unless($lateWorkerStaleCachedUrl instanceof StaleCachedUrl, RuntimeException::class, 'Expected stale cached URL row to be available for late worker test.');

    $staleCachedUrl->forceFill(['claim_token' => 'new-claim-token'])->save();

    expect(function () use ($lateWorkerStaleCachedUrl): void {
        RefreshCachedUrlAtomicallyAction::run($lateWorkerStaleCachedUrl);
    })
        ->toThrow(RuntimeException::class);

    expect(Storage::disk('page_cache')->get($cachePath))->toBe('old cached page')
        ->and($staleCachedUrl->refresh()->claim_token)->toBe('new-claim-token');
});

it('renews a stale URL retry budget and stops retrying once it is exhausted', function (): void {
    Storage::fake('page_cache');
    config()->set('capell-html-cache.invalidation.max_attempts', 2);
    config()->set('capell-html-cache.invalidation.retry_backoff_minutes', 5);

    $siteDomain = existingStaleCacheDomain();
    $staleCachedUrl = staleCacheRowForCoverage($siteDomain, '/about', StaleCachedUrl::STATUS_FAILED, [
        'attempts' => 1,
        'failed_at' => now()->subMinutes(6),
    ]);

    $cachePath = $staleCachedUrl->cache_path;
    throw_unless(is_string($cachePath), RuntimeException::class, 'Expected a stored cache path.');
    Storage::disk('page_cache')->put($cachePath, 'old cached page');
    staleCacheOriginRoute('/about', fn (): mixed => response('broken', 500, ['Content-Type' => 'text/html']));

    MarkCachedUrlStaleAction::run($staleCachedUrl->url, 'changed_again');

    expect($staleCachedUrl->refresh()->attempts)->toBe(0)
        ->and(ProcessStaleHtmlCacheAction::run(1)->attempted)->toBe(1)
        ->and($staleCachedUrl->refresh()->status)->toBe(StaleCachedUrl::STATUS_FAILED)
        ->and($staleCachedUrl->attempts)->toBe(1)
        ->and(ProcessStaleHtmlCacheAction::run(1)->attempted)->toBe(0);

    $this->travel(6)->minutes();

    expect(ProcessStaleHtmlCacheAction::run(1)->attempted)->toBe(1)
        ->and($staleCachedUrl->refresh()->status)->toBe(StaleCachedUrl::STATUS_EXHAUSTED)
        ->and($staleCachedUrl->isTerminal())->toBeFalse()
        ->and(StaleCachedUrl::terminalStatuses())->not->toContain($staleCachedUrl->status)
        ->and($staleCachedUrl->attempts)->toBe(2)
        ->and($staleCachedUrl->claim_token)->toBeNull()
        ->and(ProcessStaleHtmlCacheAction::run(1)->attempted)->toBe(0);
});

it('processes fresh pending stale urls before retrying older failed rows', function (): void {
    Storage::fake('page_cache');
    Cache::forget('capell-html-cache:stale-refresh:single-item-source');
    config()->set('capell-html-cache.invalidation.retry_backoff_minutes', 5);

    $siteDomain = existingStaleCacheDomain();
    $page = Page::factory()
        ->recycle($siteDomain->site)
        ->withTranslations()
        ->create();

    bindHtmlCacheFrontendContext($page);

    foreach (['/old', '/new', '/new-two'] as $path) {
        Storage::disk('page_cache')->put(
            resolve(HtmlCachePathResolver::class)->pathForUrl($path, $siteDomain),
            'old cached page',
        );
    }

    staleCacheOriginRoute('/old', fn (): mixed => response('broken', 500, ['Content-Type' => 'text/html']));
    staleCacheOriginRoute('/new', fn (): mixed => response('fresh pending page', 200, ['Content-Type' => 'text/html']));
    staleCacheOriginRoute('/new-two', fn (): mixed => response('fresh second pending page', 200, ['Content-Type' => 'text/html']));

    $oldFailedStaleCachedUrl = StaleCachedUrl::query()->create([
        'url' => 'https://example.test/old',
        'url_hash' => CachedModelUrl::hashUrl('https://example.test/old'),
        'path' => '/old',
        'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl('https://example.test/old'), $siteDomain->site_id, $siteDomain->id, '/old'),
        'site_id' => $siteDomain->site_id,
        'site_domain_id' => $siteDomain->getKey(),
        'language_id' => $siteDomain->language_id,
        'cache_path' => resolve(HtmlCachePathResolver::class)->pathForUrl('/old', $siteDomain),
        'error_cache_path' => resolve(HtmlCachePathResolver::class)->pathForUrl('/old', $siteDomain, error: true),
        'reason' => 'test',
        'status' => StaleCachedUrl::STATUS_FAILED,
        'attempts' => 1,
        'failed_at' => now()->subMinutes(6),
    ]);
    $oldFailedStaleCachedUrl->forceFill(['created_at' => now()->subHour()])->save();
    $newPendingStaleCachedUrl = StaleCachedUrl::query()->create([
        'url' => 'https://example.test/new',
        'url_hash' => CachedModelUrl::hashUrl('https://example.test/new'),
        'path' => '/new',
        'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl('https://example.test/new'), $siteDomain->site_id, $siteDomain->id, '/new'),
        'site_id' => $siteDomain->site_id,
        'site_domain_id' => $siteDomain->getKey(),
        'language_id' => $siteDomain->language_id,
        'cache_path' => resolve(HtmlCachePathResolver::class)->pathForUrl('/new', $siteDomain),
        'error_cache_path' => resolve(HtmlCachePathResolver::class)->pathForUrl('/new', $siteDomain, error: true),
        'reason' => 'test',
        'status' => StaleCachedUrl::STATUS_PENDING,
    ]);
    $secondPendingStaleCachedUrl = StaleCachedUrl::query()->create([
        'url' => 'https://example.test/new-two',
        'url_hash' => CachedModelUrl::hashUrl('https://example.test/new-two'),
        'path' => '/new-two',
        'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl('https://example.test/new-two'), $siteDomain->site_id, $siteDomain->id, '/new-two'),
        'site_id' => $siteDomain->site_id,
        'site_domain_id' => $siteDomain->getKey(),
        'language_id' => $siteDomain->language_id,
        'cache_path' => resolve(HtmlCachePathResolver::class)->pathForUrl('/new-two', $siteDomain),
        'error_cache_path' => resolve(HtmlCachePathResolver::class)->pathForUrl('/new-two', $siteDomain, error: true),
        'reason' => 'test',
        'status' => StaleCachedUrl::STATUS_PENDING,
    ]);

    expect(ProcessStaleHtmlCacheAction::run(1)->attempted)->toBe(1)
        ->and($newPendingStaleCachedUrl->refresh()->status)->toBe(StaleCachedUrl::STATUS_PROCESSED)
        ->and($oldFailedStaleCachedUrl->refresh()->status)->toBe(StaleCachedUrl::STATUS_FAILED)
        ->and($secondPendingStaleCachedUrl->refresh()->status)->toBe(StaleCachedUrl::STATUS_PENDING)
        ->and(ProcessStaleHtmlCacheAction::run(1)->attempted)->toBe(1)
        ->and($oldFailedStaleCachedUrl->refresh()->attempts)->toBe(2)
        ->and($oldFailedStaleCachedUrl->status)->toBe(StaleCachedUrl::STATUS_FAILED)
        ->and($secondPendingStaleCachedUrl->refresh()->status)->toBe(StaleCachedUrl::STATUS_PENDING);
});

it('processes retryable stale urls alongside pending batch capacity', function (): void {
    Storage::fake('page_cache');
    config()->set('capell-html-cache.invalidation.retry_backoff_minutes', 5);

    $siteDomain = existingStaleCacheDomain();
    $page = Page::factory()
        ->recycle($siteDomain->site)
        ->withTranslations()
        ->create();

    bindHtmlCacheFrontendContext($page);

    foreach (['/old', '/new-one', '/new-two'] as $path) {
        Storage::disk('page_cache')->put(
            resolve(HtmlCachePathResolver::class)->pathForUrl($path, $siteDomain),
            'old cached page',
        );
    }

    staleCacheOriginRoute('/old', fn (): mixed => response('still broken', 500, ['Content-Type' => 'text/html']));
    staleCacheOriginRoute('/new-one', fn (): mixed => response('fresh first pending page', 200, ['Content-Type' => 'text/html']));
    staleCacheOriginRoute('/new-two', fn (): mixed => response('fresh second pending page', 200, ['Content-Type' => 'text/html']));

    $oldFailedStaleCachedUrl = StaleCachedUrl::query()->create([
        'url' => 'https://example.test/old',
        'url_hash' => CachedModelUrl::hashUrl('https://example.test/old'),
        'path' => '/old',
        'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl('https://example.test/old'), $siteDomain->site_id, $siteDomain->id, '/old'),
        'site_id' => $siteDomain->site_id,
        'site_domain_id' => $siteDomain->getKey(),
        'language_id' => $siteDomain->language_id,
        'cache_path' => resolve(HtmlCachePathResolver::class)->pathForUrl('/old', $siteDomain),
        'error_cache_path' => resolve(HtmlCachePathResolver::class)->pathForUrl('/old', $siteDomain, error: true),
        'reason' => 'test',
        'status' => StaleCachedUrl::STATUS_FAILED,
        'attempts' => 1,
        'failed_at' => now()->subMinutes(6),
    ]);
    $firstPendingStaleCachedUrl = StaleCachedUrl::query()->create([
        'url' => 'https://example.test/new-one',
        'url_hash' => CachedModelUrl::hashUrl('https://example.test/new-one'),
        'path' => '/new-one',
        'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl('https://example.test/new-one'), $siteDomain->site_id, $siteDomain->id, '/new-one'),
        'site_id' => $siteDomain->site_id,
        'site_domain_id' => $siteDomain->getKey(),
        'language_id' => $siteDomain->language_id,
        'cache_path' => resolve(HtmlCachePathResolver::class)->pathForUrl('/new-one', $siteDomain),
        'error_cache_path' => resolve(HtmlCachePathResolver::class)->pathForUrl('/new-one', $siteDomain, error: true),
        'reason' => 'test',
        'status' => StaleCachedUrl::STATUS_PENDING,
    ]);
    $secondPendingStaleCachedUrl = StaleCachedUrl::query()->create([
        'url' => 'https://example.test/new-two',
        'url_hash' => CachedModelUrl::hashUrl('https://example.test/new-two'),
        'path' => '/new-two',
        'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl('https://example.test/new-two'), $siteDomain->site_id, $siteDomain->id, '/new-two'),
        'site_id' => $siteDomain->site_id,
        'site_domain_id' => $siteDomain->getKey(),
        'language_id' => $siteDomain->language_id,
        'cache_path' => resolve(HtmlCachePathResolver::class)->pathForUrl('/new-two', $siteDomain),
        'error_cache_path' => resolve(HtmlCachePathResolver::class)->pathForUrl('/new-two', $siteDomain, error: true),
        'reason' => 'test',
        'status' => StaleCachedUrl::STATUS_PENDING,
    ]);

    expect(ProcessStaleHtmlCacheAction::run(2)->attempted)->toBe(2)
        ->and($firstPendingStaleCachedUrl->refresh()->status)->toBe(StaleCachedUrl::STATUS_PROCESSED)
        ->and($oldFailedStaleCachedUrl->refresh()->attempts)->toBe(2)
        ->and($oldFailedStaleCachedUrl->status)->toBe(StaleCachedUrl::STATUS_FAILED)
        ->and($secondPendingStaleCachedUrl->refresh()->status)->toBe(StaleCachedUrl::STATUS_PENDING);
});

it('fills stale cache batches with timed out processing rows when pending capacity remains', function (): void {
    Storage::fake('page_cache');
    config()->set('capell-html-cache.invalidation.batch_size', '3');
    config()->set('capell-html-cache.invalidation.processing_timeout_minutes', '15');
    config()->set('capell-html-cache.invalidation.retry_backoff_minutes', '5');

    $siteDomain = existingStaleCacheDomain();
    $page = Page::factory()
        ->recycle($siteDomain->site)
        ->withTranslations()
        ->create();

    bindHtmlCacheFrontendContext($page);

    foreach (['/pending-batch', '/failed-batch', '/timed-out-batch'] as $path) {
        Storage::disk('page_cache')->put(
            resolve(HtmlCachePathResolver::class)->pathForUrl($path, $siteDomain),
            'old cached page',
        );
        staleCacheOriginRoute($path, fn (): mixed => response('fresh ' . $path, 200, ['Content-Type' => 'text/html']));
    }

    $pending = staleCacheRowForCoverage($siteDomain, '/pending-batch', StaleCachedUrl::STATUS_PENDING);
    $failed = staleCacheRowForCoverage($siteDomain, '/failed-batch', StaleCachedUrl::STATUS_FAILED, [
        'attempts' => 1,
        'failed_at' => now()->subMinutes(6),
    ]);
    $timedOut = staleCacheRowForCoverage($siteDomain, '/timed-out-batch', StaleCachedUrl::STATUS_PROCESSING, [
        'attempts' => 1,
        'claim_token' => 'timed-out-batch-claim',
    ]);
    $timedOut->forceFill(['updated_at' => now()->subMinutes(16)])->save();

    expect(ProcessStaleHtmlCacheAction::run()->attempted)->toBe(3)
        ->and($pending->refresh()->status)->toBe(StaleCachedUrl::STATUS_PROCESSED)
        ->and($failed->refresh()->status)->toBe(StaleCachedUrl::STATUS_PROCESSED)
        ->and($timedOut->refresh()->status)->toBe(StaleCachedUrl::STATUS_PROCESSED)
        ->and(ProcessStaleHtmlCacheAction::run(2)->attempted)->toBe(0);
});

it('alternates failed and timed out processing rows in single item retry batches', function (): void {
    Storage::fake('page_cache');
    Cache::forget('capell-html-cache:stale-refresh:single-retryable-source');
    config()->set('capell-html-cache.invalidation.processing_timeout_minutes', 15);
    config()->set('capell-html-cache.invalidation.retry_backoff_minutes', 5);

    $siteDomain = existingStaleCacheDomain();
    $page = Page::factory()
        ->recycle($siteDomain->site)
        ->withTranslations()
        ->create();

    bindHtmlCacheFrontendContext($page);

    foreach (['/failed', '/timed-out'] as $path) {
        Storage::disk('page_cache')->put(
            resolve(HtmlCachePathResolver::class)->pathForUrl($path, $siteDomain),
            'old cached page',
        );
    }

    staleCacheOriginRoute('/failed', fn (): mixed => response('still broken', 500, ['Content-Type' => 'text/html']));
    staleCacheOriginRoute('/timed-out', fn (): mixed => response('fresh timed out page', 200, ['Content-Type' => 'text/html']));

    $failedStaleCachedUrl = StaleCachedUrl::query()->create([
        'url' => 'https://example.test/failed',
        'url_hash' => CachedModelUrl::hashUrl('https://example.test/failed'),
        'path' => '/failed',
        'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl('https://example.test/failed'), $siteDomain->site_id, $siteDomain->id, '/failed'),
        'site_id' => $siteDomain->site_id,
        'site_domain_id' => $siteDomain->getKey(),
        'language_id' => $siteDomain->language_id,
        'cache_path' => resolve(HtmlCachePathResolver::class)->pathForUrl('/failed', $siteDomain),
        'error_cache_path' => resolve(HtmlCachePathResolver::class)->pathForUrl('/failed', $siteDomain, error: true),
        'reason' => 'test',
        'status' => StaleCachedUrl::STATUS_FAILED,
        'attempts' => 1,
        'failed_at' => now()->subMinutes(6),
    ]);
    $timedOutStaleCachedUrl = StaleCachedUrl::query()->create([
        'url' => 'https://example.test/timed-out',
        'url_hash' => CachedModelUrl::hashUrl('https://example.test/timed-out'),
        'path' => '/timed-out',
        'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl('https://example.test/timed-out'), $siteDomain->site_id, $siteDomain->id, '/timed-out'),
        'site_id' => $siteDomain->site_id,
        'site_domain_id' => $siteDomain->getKey(),
        'language_id' => $siteDomain->language_id,
        'cache_path' => resolve(HtmlCachePathResolver::class)->pathForUrl('/timed-out', $siteDomain),
        'error_cache_path' => resolve(HtmlCachePathResolver::class)->pathForUrl('/timed-out', $siteDomain, error: true),
        'reason' => 'test',
        'status' => StaleCachedUrl::STATUS_PROCESSING,
        'claim_token' => 'timed-out-claim',
        'attempts' => 1,
    ]);
    $timedOutStaleCachedUrl->forceFill(['updated_at' => now()->subMinutes(16)])->save();

    expect(ProcessStaleHtmlCacheAction::run(1)->attempted)->toBe(1)
        ->and($failedStaleCachedUrl->refresh()->attempts)->toBe(2)
        ->and($failedStaleCachedUrl->status)->toBe(StaleCachedUrl::STATUS_FAILED)
        ->and($timedOutStaleCachedUrl->refresh()->status)->toBe(StaleCachedUrl::STATUS_PROCESSING)
        ->and(ProcessStaleHtmlCacheAction::run(1)->attempted)->toBe(1)
        ->and($timedOutStaleCachedUrl->refresh()->status)->toBe(StaleCachedUrl::STATUS_PROCESSED)
        ->and(Storage::disk('page_cache')->get(resolve(HtmlCachePathResolver::class)->pathForUrl('/timed-out', $siteDomain)))->toBe('fresh timed out page');
});

it('processes stale cache command with the requested limit and optional edge purge suppression', function (bool $suppressInlineEdgePurge): void {
    Storage::fake('page_cache');
    Queue::fake();

    $siteDomain = existingStaleCacheDomain();
    $page = Page::factory()
        ->recycle($siteDomain->site)
        ->withTranslations()
        ->create();
    bindHtmlCacheFrontendContext($page);

    foreach (['/one', '/two'] as $path) {
        $url = 'https://example.test' . $path;
        $cachePath = resolve(HtmlCachePathResolver::class)->pathForUrl($path, $siteDomain);

        Storage::disk('page_cache')->put($cachePath, 'old cached page');
        staleCacheOriginRoute($path, fn (): mixed => response('fresh cached page', 200, ['Content-Type' => 'text/html']));
        StaleCachedUrl::query()->create([
            'url' => $url,
            'url_hash' => CachedModelUrl::hashUrl($url),
            'path' => $path,
            'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl($url), $siteDomain->site_id, $siteDomain->id, $path),
            'site_id' => $siteDomain->site_id,
            'site_domain_id' => $siteDomain->getKey(),
            'language_id' => $siteDomain->language_id,
            'cache_path' => $cachePath,
            'error_cache_path' => resolve(HtmlCachePathResolver::class)->pathForUrl($path, $siteDomain, error: true),
            'reason' => 'test',
            'status' => StaleCachedUrl::STATUS_PENDING,
        ]);
    }

    $options = ['--limit' => 1];

    if ($suppressInlineEdgePurge) {
        $options['--suppress-inline-edge-purge'] = true;
    }

    $this->artisan('capell:html-cache:process-stale', $options)
        ->expectsOutput(__('capell-html-cache::cache.refresh_summary', ['succeeded' => 1, 'failed' => 0, 'deferred' => 0, 'not_applicable' => 0, 'attempted' => 1]))
        ->assertSuccessful();

    expect(StaleCachedUrl::query()->where('status', StaleCachedUrl::STATUS_PROCESSED)->count())->toBe(1)
        ->and(StaleCachedUrl::query()->where('status', StaleCachedUrl::STATUS_PENDING)->count())->toBe(1);

    if ($suppressInlineEdgePurge) {
        PurgeEdgeCacheAction::assertNotPushed();
    } else {
        PurgeEdgeCacheAction::assertPushed();
    }
})->with(['default purge' => false, 'suppressed purge' => true]);

/**
 * @param  array<string, mixed>  $attributes
 */
function staleCacheRowForCoverage(SiteDomain $siteDomain, string $path, string $status, array $attributes = []): StaleCachedUrl
{
    $url = 'https://example.test' . $path;

    return StaleCachedUrl::query()->create([
        'url' => $url,
        'url_hash' => CachedModelUrl::hashUrl($url),
        'path' => $path,
        'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl($url), $siteDomain->site_id, $siteDomain->getKey(), $path),
        'site_id' => $siteDomain->site_id,
        'site_domain_id' => $siteDomain->getKey(),
        'language_id' => $siteDomain->language_id,
        'cache_path' => resolve(HtmlCachePathResolver::class)->pathForUrl($path, $siteDomain),
        'error_cache_path' => resolve(HtmlCachePathResolver::class)->pathForUrl($path, $siteDomain, error: true),
        'reason' => 'test',
        'status' => $status,
        ...$attributes,
    ]);
}

it('keeps rejected refresh statuses retryable despite private or no-store directives', function (int $status): void {
    Storage::fake('page_cache');
    $domain = existingStaleCacheDomain();
    $page = Page::factory()->recycle($domain->site)->withTranslations()->create();
    bindHtmlCacheFrontendContext($page);
    $kernel = Mockery::mock(Kernel::class);
    $kernel->shouldReceive('terminate')->times(4);
    app()->instance(Kernel::class, $kernel);
    $disk = Storage::disk('page_cache');

    $cases = [];
    foreach ([
        'private' => ['private', HtmlCacheEligibilityReason::ResponsePrivate],
        'no-store' => ['public, no-store', HtmlCacheEligibilityReason::ResponseNoStore],
    ] as $variant => [$directive, $reason]) {
        $path = '/rejected-' . $variant;
        $row = staleCacheRowForCoverage($domain, $path, StaleCachedUrl::STATUS_PENDING);
        $cachePath = $row->cache_path;
        assert(is_string($cachePath));
        $disk->put($cachePath, 'last successful HTML');

        $response = response('origin rejected refresh', $status, ['Content-Type' => 'text/html', 'Cache-Control' => $directive]);
        $kernel->shouldReceive('handle')
            ->withArgs(static fn (Request $request): bool => $request->getPathInfo() === $path)
            ->twice()
            ->andReturn($response);
        $cases[] = [$row, $cachePath, $response, $reason];
    }

    expect(ProcessStaleHtmlCacheAction::run(2))->toHaveProperties(['attempted' => 2, 'failed' => 2, 'notApplicable' => 0]);

    foreach ($cases as [$row, $cachePath, $response, $reason]) {
        expect($row->refresh()->status)->toBe(StaleCachedUrl::STATUS_FAILED, $row->path)
            ->and($row->isTerminal())->toBeFalse()
            ->and($row->attempts)->toBe(1)
            ->and($disk->get($cachePath))->toBe('last successful HTML')
            ->and(resolve(PageCache::class)->rejectionReason(Request::create($row->url), $response))->toBe($status === 404 ? $reason : HtmlCacheEligibilityReason::UncacheableResponseStatus)
            ->and($reason->isDeterministicForStaleRefresh($status))->toBeFalse();
    }

    $this->travel(6)->minutes();

    expect(ProcessStaleHtmlCacheAction::run(2))->toHaveProperties(['attempted' => 2, 'failed' => 2, 'notApplicable' => 0]);

    foreach ($cases as [$row]) {
        expect($row->refresh()->attempts)->toBe(2, $row->path)
            ->and($row->status)->toBe(StaleCachedUrl::STATUS_FAILED);
    }
})->with([302, 403, 404, 429, 500]);

it('retires successful non-cacheable responses and serves fresh origin output to the next anonymous request', function (array $headers, string $reason): void {
    Storage::fake('page_cache');
    $domain = existingStaleCacheDomain();
    $page = Page::factory()->recycle($domain->site)->withTranslations()->create();
    bindHtmlCacheFrontendContext($page);
    /** @var array<string, string> $headers */
    $kernel = Mockery::mock(Kernel::class);
    $kernel->shouldReceive('handle')->once()->andReturnUsing(static fn (Request $request): Response => resolve(HtmlCacheMiddleware::class)->handle(
        $request,
        static fn (): Response => response('fresh non-cacheable response', 200, $headers),
    ));
    $kernel->shouldReceive('terminate')->once();
    app()->instance(Kernel::class, $kernel);
    $row = staleCacheRowForCoverage($domain, '/uncacheable-response', StaleCachedUrl::STATUS_PENDING);
    $cachePath = $row->cache_path;
    $errorCachePath = $row->error_cache_path;
    assert(is_string($cachePath));
    assert(is_string($errorCachePath));
    Storage::disk('page_cache')->put($cachePath, 'last successful HTML');
    Storage::disk('page_cache')->put($cachePath . PageCache::FRAGMENT_METADATA_EXTENSION, '{}');
    Storage::disk('page_cache')->put($errorCachePath, 'old cached error HTML');
    Storage::disk('page_cache')->put($errorCachePath . PageCache::FRAGMENT_METADATA_EXTENSION, '{}');
    $cachedUrl = CachedModelUrl::query()->create([
        'url' => $row->url,
        'url_hash' => $row->url_hash,
        'path' => $row->path,
        'site_id' => $domain->site_id,
        'site_domain_id' => $domain->getKey(),
        'language_id' => $domain->language_id,
        'cacheable_type' => $page->getMorphClass(),
        'cacheable_id' => $page->getKey(),
        'cached_at' => now(),
        'last_seen_at' => now(),
    ]);

    ProcessStaleHtmlCacheAction::run(1);

    $request = Request::create($row->url);
    app()->instance('request', $request);
    $originCalls = 0;
    $anonymousResponse = resolve(HtmlCacheMiddleware::class)->handle($request, function () use ($headers, &$originCalls): Response {
        $originCalls++;

        return response('fresh non-cacheable response', 200, $headers);
    });

    expect($row->refresh()->status)->toBe(StaleCachedUrl::STATUS_NOT_APPLICABLE)
        ->and($row->last_error)->toContain('Reason: ' . $reason)
        ->and($anonymousResponse->getContent())->toBe('fresh non-cacheable response')
        ->and($anonymousResponse->getStatusCode())->toBe(200)
        ->and($originCalls)->toBe(1)
        ->and(Storage::disk('page_cache')->exists($cachePath))->toBeFalse()
        ->and(Storage::disk('page_cache')->exists($cachePath . PageCache::FRAGMENT_METADATA_EXTENSION))->toBeFalse()
        ->and(Storage::disk('page_cache')->exists($errorCachePath))->toBeFalse()
        ->and(Storage::disk('page_cache')->exists($errorCachePath . PageCache::FRAGMENT_METADATA_EXTENSION))->toBeFalse()
        ->and(CachedModelUrl::query()->whereKey($cachedUrl->getKey())->exists())->toBeFalse()
        ->and(ProcessStaleHtmlCacheAction::run(1)->attempted)->toBe(0);
})->with([
    'XML document' => [['Content-Type' => 'application/xml'], 'non_html_response'],
    'private HTML' => [['Content-Type' => 'text/html', 'Cache-Control' => 'private'], 'response_private'],
    'no-store HTML' => [['Content-Type' => 'text/html', 'Cache-Control' => 'public, no-store'], 'response_no_store'],
]);

it('uses accepted response statuses for every retirement reason', function (): void {
    foreach ([
        HtmlCacheEligibilityReason::ConfiguredBypassRule,
        HtmlCacheEligibilityReason::PackageCacheBlocking,
        HtmlCacheEligibilityReason::PackageSensitiveOutput,
        HtmlCacheEligibilityReason::NonHtmlResponse,
        HtmlCacheEligibilityReason::ResponsePrivate,
        HtmlCacheEligibilityReason::ResponseNoStore,
        HtmlCacheEligibilityReason::RedirectUrl,
    ] as $reason) {
        foreach ([199, 200, 204, 299, 301, 308, 302, 303, 307, 404, 500] as $status) {
            $accepted = $status >= 200 && $status < 300
                || ($reason === HtmlCacheEligibilityReason::RedirectUrl && in_array($status, [301, 308], true));

            expect($reason->isDeterministicForStaleRefresh($status))->toBe($accepted);
        }
    }
});

it('keeps status precedence and evicts every accepted retirement across refresh paths', function (bool $queued): void {
    Storage::fake('page_cache');
    $domain = existingStaleCacheDomain();
    $page = Page::factory()->recycle($domain->site)->withTranslations()->create();
    bindHtmlCacheFrontendContext($page);

    config(['capell-html-cache.bypass.paths' => ['/status-precedence-404']]);
    $cases = [
        'configured bypass 404' => [404, 'configured'],
        'public 301' => [301, 'none'],
        'public 308 with package blocker' => [308, 'package'],
        'public 302' => [302, 'none'],
        'public 303' => [303, 'none'],
        'public 307' => [307, 'none'],
        'package-blocked 500' => [500, 'package'],
    ];

    foreach ($cases as $scenario => [$status, $blocker]) {
        $path = '/status-precedence-' . $status;
        $row = staleCacheRowForCoverage($domain, $path, StaleCachedUrl::STATUS_PENDING);
        $cachePath = $row->cache_path;
        $errorPath = $row->error_cache_path;
        assert(is_string($cachePath));
        assert(is_string($errorPath));
        Storage::disk('page_cache')->put($cachePath, 'last successful HTML');
        Storage::disk('page_cache')->put($errorPath, 'previous error HTML');
        staleCacheOriginRoute($path, function () use ($status, $blocker): Response {
            if ($blocker === 'package') {
                RecordExtensionRenderContributionAction::run(
                    packageName: 'vendor/private-output',
                    surface: 'frontend',
                    contributionType: 'frontend-component',
                    contributionClass: 'Vendor\\PrivateOutput',
                    elapsedMilliseconds: 1,
                    frontendRenderBudgetMs: 10,
                    cacheTags: [],
                    cacheable: false,
                    sensitiveOutput: false,
                    variesBy: [],
                );
            }

            return response('origin output', $status, ['Content-Type' => 'text/html', 'Cache-Control' => 'public', 'Location' => '/new-location']);
        });
        $permanent = in_array($status, [301, 308], true);
        $refresh = static fn (): mixed => $queued ? RefreshOriginStaleCachedUrlAction::run($row->url) : ProcessStaleHtmlCacheAction::run(1);
        if ($queued && ! $permanent) {
            expect($refresh)->toThrow(RuntimeException::class);
        } else {
            $refresh();
        }

        expect($row->refresh()->status)->toBe($permanent ? StaleCachedUrl::STATUS_NOT_APPLICABLE : StaleCachedUrl::STATUS_FAILED, $scenario)
            ->and($row->attempts)->toBe(1)
            ->and($row->isTerminal())->toBe($permanent)
            ->and(Storage::disk('page_cache')->exists($cachePath))->toBe(! $permanent)
            ->and(Storage::disk('page_cache')->exists($errorPath))->toBe(! $permanent);
        if ($permanent) {
            expect($row->last_error)->toContain('Reason: redirect_url');
        } else {
            expect($row->last_error)->toContain('response status was ' . $status);
        }

        if ($blocker === 'package' && ! $permanent) {
            expect(HtmlCacheEligibilityReason::PackageCacheBlocking->isDeterministicForStaleRefresh($status))->toBeFalse();
        }

        $row->delete();
    }
})->with(['queued' => true, 'process-stale' => false]);

it('evicts all retired package artefacts before the next anonymous request on every generation path', function (string $path, bool $sensitive): void {
    Storage::fake('page_cache');
    $domain = existingStaleCacheDomain();
    $page = Page::factory()->recycle($domain->site)->withTranslations()->create();
    bindHtmlCacheFrontendContext($page);
    $row = staleCacheRowForCoverage($domain, '/package-retirement', StaleCachedUrl::STATUS_PENDING);
    $cachePath = $row->cache_path;
    $errorPath = $row->error_cache_path;
    assert(is_string($cachePath));
    assert(is_string($errorPath));
    $artefacts = [$cachePath, $errorPath, $cachePath . PageCache::FRAGMENT_METADATA_EXTENSION, $errorPath . PageCache::FRAGMENT_METADATA_EXTENSION];
    foreach ($artefacts as $artefact) {
        Storage::disk('page_cache')->put($artefact, 'previous public output');
    }

    $index = CachedModelUrl::query()->create([
        'url' => $row->url, 'url_hash' => $row->url_hash, 'path' => $row->path,
        'site_id' => $domain->site_id, 'site_domain_id' => $domain->getKey(), 'language_id' => $domain->language_id,
        'cacheable_type' => $page->getMorphClass(), 'cacheable_id' => $page->getKey(), 'cached_at' => now(), 'last_seen_at' => now(),
    ]);
    $originCalls = 0;
    staleCacheOriginRoute('/package-retirement', function () use (&$originCalls, $sensitive): Response {
        $originCalls++;
        RecordExtensionRenderContributionAction::run(
            packageName: 'vendor/private-output',
            surface: 'frontend',
            contributionType: 'frontend-component',
            contributionClass: 'Vendor\\PrivateOutput',
            elapsedMilliseconds: 1,
            frontendRenderBudgetMs: 10,
            cacheTags: [],
            cacheable: $sensitive,
            sensitiveOutput: $sensitive,
            variesBy: [],
        );

        return response('fresh private origin output', 200, ['Content-Type' => 'text/html', 'Cache-Control' => 'private']);
    });
    if ($path === 'static') {
        $page->pageUrls()->delete();
        config(['capell-html-cache.static_generation.internal_requests' => true]);
        $registry = new StaticSiteExtensionRegistry;
        $registry->register('retirement', static function (Site $site, SiteDomain $siteDomain, Closure $visit) use ($row): void {
            $visit($row->url);
        });
        app()->instance(StaticSiteExtensionRegistry::class, $registry);
        new StaticSiteGenerator($domain->site)->process();
    } elseif ($path === 'queued') {
        RefreshOriginStaleCachedUrlAction::run($row->url);
    } else {
        ProcessStaleHtmlCacheAction::run(1);
    }

    foreach ($artefacts as $artefact) {
        expect(Storage::disk('page_cache')->exists($artefact))->toBeFalse();
    }

    expect(CachedModelUrl::query()->whereKey($index->getKey())->exists())->toBeFalse();
    if ($path !== 'static') {
        expect($row->refresh()->status)->toBe(StaleCachedUrl::STATUS_NOT_APPLICABLE)
            ->and($row->last_error)->toContain($sensitive ? 'Reason: package_sensitive_output' : 'Reason: package_cache_blocking');
    }

    $request = Request::create($row->url);
    app()->instance('request', $request);
    $response = resolve(HtmlCacheMiddleware::class)->handle($request, function () use (&$originCalls): Response {
        $originCalls++;

        return response('fresh private origin output', 200, ['Content-Type' => 'text/html', 'Cache-Control' => 'private']);
    });
    expect($response->getContent())->toBe('fresh private origin output')
        ->and($originCalls)->toBe(2);
})->with(['queued', 'process-stale', 'static'])->with(['blocking' => false, 'sensitive' => true]);

it('evicts retired filesystem artefacts before tracking migrations are installed', function (): void {
    Storage::fake('page_cache');
    $domain = existingStaleCacheDomain();
    $page = Page::factory()->recycle($domain->site)->withTranslations()->create();
    bindHtmlCacheFrontendContext($page);
    $url = 'https://example.test/before-tracking';
    $paths = resolve(HtmlCachePathResolver::class);
    $cachePath = $paths->pathForRequestUrl($url, $domain);
    $errorPath = $paths->pathForRequestUrl($url, $domain, error: true);
    $artefacts = [$cachePath, $errorPath, $cachePath . PageCache::FRAGMENT_METADATA_EXTENSION, $errorPath . PageCache::FRAGMENT_METADATA_EXTENSION];
    foreach ($artefacts as $artefact) {
        Storage::disk('page_cache')->put($artefact, 'previous public output');
    }

    Schema::drop((new CachedModelUrl)->getTable());
    $request = Request::create($url);
    app()->instance('request', $request);

    expect(retireDeclaredHtmlCacheOriginResponse($request, response('private output', 200, ['Content-Type' => 'text/html', 'Cache-Control' => 'private'])))
        ->toBe(HtmlCacheEligibilityReason::ResponsePrivate);
    foreach ($artefacts as $artefact) {
        expect(Storage::disk('page_cache')->exists($artefact))->toBeFalse();
    }

    expect(Schema::hasTable((new CachedModelUrl)->getTable()))->toBeFalse();
});

it('retires a non-html response from a route outside the cache middleware and evicts its artefacts', function (): void {
    Storage::fake('page_cache');
    $domain = existingStaleCacheDomain();
    $page = Page::factory()->recycle($domain->site)->withTranslations()->create();
    bindHtmlCacheFrontendContext($page);
    // Deliberately not behind HtmlCacheMiddleware: no origin decision is ever recorded.
    Route::get('/sitemap-xml', static fn (): Response => response('<urlset/>', 200, [
        'Content-Type' => 'application/xml',
        'Cache-Control' => 'max-age=3600, public',
    ]));
    $row = staleCacheRowForCoverage($domain, '/sitemap-xml', StaleCachedUrl::STATUS_PENDING);
    $cachePath = $row->cache_path;
    $errorCachePath = $row->error_cache_path;
    assert(is_string($cachePath));
    assert(is_string($errorCachePath));
    foreach ([$cachePath, $errorCachePath] as $artefact) {
        Storage::disk('page_cache')->put($artefact, 'stale artefact');
        Storage::disk('page_cache')->put($artefact . PageCache::FRAGMENT_METADATA_EXTENSION, '{}');
    }

    $cachedUrl = CachedModelUrl::query()->create([
        'url' => $row->url,
        'url_hash' => $row->url_hash,
        'path' => $row->path,
        'site_id' => $domain->site_id,
        'site_domain_id' => $domain->getKey(),
        'language_id' => $domain->language_id,
        'cacheable_type' => $page->getMorphClass(),
        'cacheable_id' => $page->getKey(),
        'cached_at' => now(),
        'last_seen_at' => now(),
    ]);

    $result = ProcessStaleHtmlCacheAction::run(1);

    expect($result)->toHaveProperties(['attempted' => 1, 'succeeded' => 0, 'failed' => 0, 'notApplicable' => 1])
        ->and($row->refresh()->status)->toBe(StaleCachedUrl::STATUS_NOT_APPLICABLE)
        ->and($row->last_error)->toContain('Reason: non_html_response')
        ->and(Storage::disk('page_cache')->exists($cachePath))->toBeFalse()
        ->and(Storage::disk('page_cache')->exists($cachePath . PageCache::FRAGMENT_METADATA_EXTENSION))->toBeFalse()
        ->and(Storage::disk('page_cache')->exists($errorCachePath))->toBeFalse()
        ->and(Storage::disk('page_cache')->exists($errorCachePath . PageCache::FRAGMENT_METADATA_EXTENSION))->toBeFalse()
        ->and(CachedModelUrl::query()->whereKey($cachedUrl->getKey())->exists())->toBeFalse()
        ->and(ProcessStaleHtmlCacheAction::run(1)->attempted)->toBe(0);
});

it('keeps failing an html page rendered outside the cache middleware', function (): void {
    Storage::fake('page_cache');
    $domain = existingStaleCacheDomain();
    $page = Page::factory()->recycle($domain->site)->withTranslations()->create();
    bindHtmlCacheFrontendContext($page);
    // A page that lost frontend.cache renders Laravel's default private headers.
    Route::get('/lost-middleware', static fn (): Response => response('<main>Page</main>', 200, [
        'Content-Type' => 'text/html; charset=UTF-8',
        'Cache-Control' => 'no-cache, private',
    ]));
    $row = staleCacheRowForCoverage($domain, '/lost-middleware', StaleCachedUrl::STATUS_PENDING);
    $cachePath = $row->cache_path;
    assert(is_string($cachePath));
    Storage::disk('page_cache')->put($cachePath, 'stale artefact');

    $result = ProcessStaleHtmlCacheAction::run(1);

    expect($result->notApplicable)->toBe(0)
        ->and($result->failed)->toBe(1)
        ->and($row->refresh()->status)->not->toBe(StaleCachedUrl::STATUS_NOT_APPLICABLE)
        ->and($row->last_error)->toContain('HTML cache middleware')
        ->and(Storage::disk('page_cache')->get($cachePath))->toBe('stale artefact');
});

it('retries the render once when the publication guard rejects it mid-render', function (): void {
    Storage::fake('page_cache');
    $domain = existingStaleCacheDomain();
    bindHtmlCacheFrontendContext(Page::factory()->recycle($domain->site)->withTranslations()->create());
    $renders = 0;
    staleCacheOriginRoute('/raced', function () use (&$renders): Response {
        if ($renders++ === 0) {
            resolve(HtmlCachePublicationGuard::class)->invalidate(static fn (): bool => true);
        }

        return response('fresh raced page', 200, ['Content-Type' => 'text/html']);
    });
    $row = staleCacheRowForCoverage($domain, '/raced', StaleCachedUrl::STATUS_PENDING);
    $cachePath = $row->cache_path;
    assert(is_string($cachePath));

    $result = ProcessStaleHtmlCacheAction::run(1);

    expect($result)->toHaveProperties(['attempted' => 1, 'succeeded' => 1, 'failed' => 0, 'notApplicable' => 0])
        ->and($renders)->toBe(2)
        ->and(Storage::disk('page_cache')->get($cachePath))->toBe('fresh raced page')
        ->and($row->refresh()->status)->toBe(StaleCachedUrl::STATUS_PROCESSED);
});

it('names the publication guard when the retried render is rejected again', function (): void {
    Storage::fake('page_cache');
    $domain = existingStaleCacheDomain();
    bindHtmlCacheFrontendContext(Page::factory()->recycle($domain->site)->withTranslations()->create());
    $renders = 0;
    staleCacheOriginRoute('/raced', function () use (&$renders): Response {
        $renders++;
        resolve(HtmlCachePublicationGuard::class)->invalidate(static fn (): bool => true);

        return response('fresh raced page', 200, ['Content-Type' => 'text/html']);
    });
    $row = staleCacheRowForCoverage($domain, '/raced', StaleCachedUrl::STATUS_PENDING);
    $cachePath = $row->cache_path;
    assert(is_string($cachePath));

    $result = ProcessStaleHtmlCacheAction::run(1);

    expect($result)->toHaveProperties(['attempted' => 1, 'succeeded' => 0, 'failed' => 1, 'notApplicable' => 0])
        ->and($renders)->toBe(2)
        ->and(Storage::disk('page_cache')->exists($cachePath))->toBeFalse()
        ->and($row->refresh()->status)->toBe(StaleCachedUrl::STATUS_FAILED)
        ->and($row->last_error)->toContain('the publication guard rejected the render twice');
});

it('purges the edge for a url once its refresh replaces the copy a live-worker site-wide change kept', function (): void {
    Storage::fake('page_cache');
    Queue::fake();
    config()->set('capell-html-cache.invalidation.mode', 'instant');
    config()->set('capell-html-cache.origin_stale_while_revalidate.enabled', true);
    config()->set('capell-html-cache.origin_stale_while_revalidate.connection');

    $siteDomain = existingStaleCacheDomain();
    $page = Page::factory()->recycle($siteDomain->site)->withTranslations()->create();
    $url = 'https://example.test/about';
    $cachePath = resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $siteDomain);

    bindHtmlCacheFrontendContext($page);
    Storage::disk('page_cache')->put($cachePath, 'old cached page');
    staleCacheOriginRoute('/about', fn (): mixed => response('<main>refreshed page</main>', 200, ['Content-Type' => 'text/html']));
    CachedModelUrl::query()->create([
        'url' => $url,
        'url_hash' => CachedModelUrl::hashUrl($url),
        'path' => '/about',
        'site_id' => $siteDomain->site_id,
        'site_domain_id' => $siteDomain->getKey(),
        'language_id' => $siteDomain->language_id,
        'cacheable_type' => $page->getMorphClass(),
        'cacheable_id' => $page->getKey(),
        'cached_at' => now(),
        'last_seen_at' => now(),
    ]);
    // Durable-queue detection only; set after the fixtures so their observers keep the test queue.
    config()->set('queue.default', 'edge-refresh-test');
    config()->set('queue.connections.edge-refresh-test', ['driver' => 'database']);

    resolve(RefreshWorkerHeartbeat::class)->touch('edge-refresh-test');

    (new HtmlFrontendOutputCacheInvalidator)->invalidateAll();

    // Marking keeps the copy and purges nothing: the edge would only refetch the same old origin copy.
    expect(Storage::disk('page_cache')->get($cachePath))->toBe('old cached page');
    PurgeEdgeCacheAction::assertNotPushed();

    $stale = StaleCachedUrl::query()->where('url', $url)->sole();
    expect(ClaimStaleCachedUrlAction::run($stale))->toBeTrue();
    RefreshCachedUrlAtomicallyAction::run($stale->refresh());

    expect(Storage::disk('page_cache')->get($cachePath))->toContain('refreshed page');
    PurgeEdgeCacheAction::assertPushed(
        fn (PurgeEdgeCacheAction $action, array $parameters): bool => $parameters[0] instanceof EdgeCachePurgeData
            && $parameters[0]->urls === [$url],
    );
});

/**
 * Runs one scheduler minute: every due html-cache event, in the order the scheduler would.
 * Command events run in-process instead of spawning an Artisan child.
 */
function runDueHtmlCacheSchedule(): void
{
    foreach (resolve(Schedule::class)->dueEvents(app()) as $event) {
        if (! $event instanceof ScheduledEvent) {
            continue;
        }

        $command = is_string($event->command) && preg_match('/(capell:html-cache:[a-z-]+)/', $event->command, $matches) === 1
            ? $matches[1]
            : null;

        if ($command !== null) {
            Artisan::call($command);

            continue;
        }

        if (is_string($event->description) && str_starts_with($event->description, 'capell-html-cache:')) {
            $event->run(app());
        }
    }
}

function quietPricingPage(SiteDomain $siteDomain, string $content): string
{
    $url = 'https://example.test/pricing';
    $cachePath = resolve(HtmlCachePathResolver::class)->pathForUrl('/pricing', $siteDomain);
    Storage::disk('page_cache')->put($cachePath, $content);
    CachedModelUrl::query()->create([
        'url' => $url,
        'url_hash' => CachedModelUrl::hashUrl($url),
        'path' => '/pricing',
        'site_id' => $siteDomain->site_id,
        'site_domain_id' => $siteDomain->getKey(),
        'language_id' => $siteDomain->language_id,
        'cacheable_type' => $siteDomain->getMorphClass(),
        'cacheable_id' => $siteDomain->getKey(),
        'cached_at' => now(),
        'last_seen_at' => now(),
    ]);

    return $cachePath;
}

it('rebuilds a quiet page stale-marked in instant mode instead of clearing it at the maximum stale age', function (): void {
    Storage::fake('page_cache');
    Queue::fake();
    config()->set('capell-html-cache.invalidation.mode', 'instant');
    config()->set('capell-html-cache.origin_stale_while_revalidate.max_stale_age_seconds', 900);
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:30'));
    $siteDomain = existingStaleCacheDomain();
    bindHtmlCacheFrontendContext(Page::factory()->recycle($siteDomain->site)->withTranslations()->create());
    $cachePath = quietPricingPage($siteDomain, 'pricing before the release');
    staleCacheOriginRoute('/pricing', fn (): mixed => response('pricing after the release', 200, ['Content-Type' => 'text/html']));

    // A release changes site-wide output; nobody requests /pricing afterwards.
    MarkAllCachedUrlsStaleAction::run('frontend_output_changed');

    for ($minute = 1; $minute <= 20; $minute++) {
        $this->travel(1)->minutes();
        runDueHtmlCacheSchedule();

        expect(Storage::disk('page_cache')->exists($cachePath))->toBeTrue("/pricing went missing {$minute} minutes after the release");
    }

    expect(Storage::disk('page_cache')->get($cachePath))->toBe('pricing after the release')
        ->and(StaleCachedUrl::query()->sole()->status)->toBe(StaleCachedUrl::STATUS_PROCESSED);
});

it('rebuilds a page whose refresh missed the maximum stale age', function (): void {
    Storage::fake('page_cache');
    Queue::fake();
    config()->set('capell-html-cache.invalidation.mode', 'instant');
    config()->set('capell-html-cache.origin_stale_while_revalidate.max_stale_age_seconds', 900);

    $siteDomain = existingStaleCacheDomain();
    bindHtmlCacheFrontendContext(Page::factory()->recycle($siteDomain->site)->withTranslations()->create());
    $cachePath = quietPricingPage($siteDomain, 'pricing before the release');
    $origin = new class
    {
        public bool $healthy = false;
    };
    staleCacheOriginRoute('/pricing', fn (): mixed => $origin->healthy
            ? response('pricing after the release', 200, ['Content-Type' => 'text/html'])
            : response('origin unavailable', 500, ['Content-Type' => 'text/html']));
    MarkAllCachedUrlsStaleAction::run('frontend_output_changed');
    Artisan::call('capell:html-cache:process-stale');

    // The refresh failed, so the old copy may not outlive the maximum stale age.
    $this->travel(901)->seconds();
    expect(ExpireOverdueStaleCachedUrlsAction::run())->toBe(1)
        ->and(Storage::disk('page_cache')->exists($cachePath))->toBeFalse()
        ->and(StaleCachedUrl::query()->sole()->status)->toBe(StaleCachedUrl::STATUS_PENDING);

    $origin->healthy = true;
    Artisan::call('capell:html-cache:process-stale');

    expect(Storage::disk('page_cache')->get($cachePath))->toBe('pricing after the release')
        ->and(StaleCachedUrl::query()->sole()->status)->toBe(StaleCachedUrl::STATUS_PROCESSED);
});

it('keeps a copy published after the clear when the rebuild misses the maximum stale age', function (): void {
    Storage::fake('page_cache');
    Queue::fake();
    config()->set('capell-html-cache.invalidation.mode', 'instant');
    config()->set('capell-html-cache.origin_stale_while_revalidate.max_stale_age_seconds', 900);

    $siteDomain = existingStaleCacheDomain();
    bindHtmlCacheFrontendContext(Page::factory()->recycle($siteDomain->site)->withTranslations()->create());
    $cachePath = quietPricingPage($siteDomain, 'pricing before the release');
    staleCacheOriginRoute('/pricing', fn (): mixed => response('origin unavailable', 500, ['Content-Type' => 'text/html']));
    MarkAllCachedUrlsStaleAction::run('frontend_output_changed');
    $this->travel(901)->seconds();
    ExpireOverdueStaleCachedUrlsAction::run();
    // A visitor's request publishes a current copy while the processor keeps failing.
    Storage::disk('page_cache')->put($cachePath, 'pricing published by a visitor');
    Artisan::call('capell:html-cache:process-stale');

    $this->travel(901)->seconds();

    expect(ExpireOverdueStaleCachedUrlsAction::run())->toBe(1)
        ->and(StaleCachedUrl::query()->sole()->status)->toBe(StaleCachedUrl::STATUS_NOT_APPLICABLE)
        ->and(Storage::disk('page_cache')->get($cachePath))->toBe('pricing published by a visitor');

    $this->travel(901)->seconds();

    expect(ExpireOverdueStaleCachedUrlsAction::run())->toBe(0);
});

it('keeps a stale mark that lands while the expiry is clearing its batch', function (): void {
    config()->set('capell-html-cache.invalidation.mode', 'instant');
    config()->set('capell-html-cache.origin_stale_while_revalidate.max_stale_age_seconds', 900);
    MarkCachedUrlStaleAction::run('https://example.test/pricing', 'frontend_output_changed');
    // An earlier expiry already cleared /about and queued it to rebuild; a visitor republished it since.
    MarkCachedUrlStaleAction::run('https://example.test/about', ExpireOverdueStaleCachedUrlsAction::REGENERATE_REASON);
    $this->travel(901)->seconds();
    // While the expiry works through /pricing, an editor changes /about.
    $edited = false;
    DB::listen(static function (QueryExecuted $query) use (&$edited): void {
        if (! $edited && str_starts_with(strtolower($query->sql), 'update "stale_cached_urls"')) {
            $edited = true;
            MarkCachedUrlStaleAction::run('https://example.test/about', 'page_updated');
        }
    });

    ExpireOverdueStaleCachedUrlsAction::run();

    expect(StaleCachedUrl::query()->where('url', 'https://example.test/about')->sole()->only(['status', 'reason']))->toBe([
        'status' => StaleCachedUrl::STATUS_PENDING,
        'reason' => 'page_updated',
    ]);
});
