<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Translation;
use Capell\Frontend\Actions\Performance\RecordExtensionRenderContributionAction;
use Capell\HtmlCache\Actions\MarkCachedUrlStaleAction;
use Capell\HtmlCache\Actions\ProcessStaleHtmlCacheAction;
use Capell\HtmlCache\Actions\RefreshCachedUrlAtomicallyAction;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Models\StaleCachedUrl;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

require_once dirname(__DIR__) . '/Support/CachedModelUrlsTestSupport.php';

uses(HtmlCacheTestCase::class);

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

    $siteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => null,
    ]);
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

it('clears cached html immediately when a site domain changes in scheduled mode', function (): void {
    Storage::fake('page_cache');
    config()->set('capell-html-cache.invalidation.mode', 'scheduled');

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

    $siteDomain->update(['domain' => 'new-example.test']);

    expect(Storage::disk('page_cache')->exists($cachePath))->toBeFalse()
        ->and(CachedModelUrl::query()->where('url', $url)->exists())->toBeFalse()
        ->and(StaleCachedUrl::query()->where('url', $url)->exists())->toBeFalse();
});

it('clears cached html immediately when a site domain is deleted in scheduled mode', function (): void {
    Storage::fake('page_cache');
    config()->set('capell-html-cache.invalidation.mode', 'scheduled');

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

    $siteDomain->delete();

    expect(Storage::disk('page_cache')->exists($cachePath))->toBeFalse()
        ->and(CachedModelUrl::query()->where('url', $url)->exists())->toBeFalse();
});

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
    Route::get('/about', fn (): mixed => response('fresh cached page', 200, ['Content-Type' => 'text/html']));

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

    expect(ProcessStaleHtmlCacheAction::run(1))->toBe(1)
        ->and(Storage::disk('page_cache')->get($cachePath))->toBe('fresh cached page')
        ->and($staleCachedUrl->refresh()->status)->toBe(StaleCachedUrl::STATUS_PROCESSED)
        ->and($staleCachedUrl->processed_at)->not->toBeNull();
});

it('keeps the previous cached html when stale refresh fails', function (): void {
    Storage::fake('page_cache');

    $siteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => null,
    ]);
    $url = 'https://example.test/about';
    $cachePath = resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $siteDomain);

    Storage::disk('page_cache')->put($cachePath, 'old cached page');
    Route::get('/about', fn (): mixed => response('broken', 500, ['Content-Type' => 'text/html']));

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

    expect(ProcessStaleHtmlCacheAction::run(1))->toBe(1)
        ->and(Storage::disk('page_cache')->get($cachePath))->toBe('old cached page')
        ->and($staleCachedUrl->refresh()->status)->toBe(StaleCachedUrl::STATUS_FAILED)
        ->and($staleCachedUrl->last_error)->toContain('response status was 500');
});

it('rejects stale refresh cache paths outside the page cache disk root', function (): void {
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

    bindHtmlCacheFrontendContext($page);
    Route::get('/about', fn (): mixed => response('fresh cached page', 200, ['Content-Type' => 'text/html']));

    $staleCachedUrl = StaleCachedUrl::query()->create([
        'url' => $url,
        'url_hash' => CachedModelUrl::hashUrl($url),
        'path' => '/about',
        'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl($url), $siteDomain->site_id, $siteDomain->getKey(), '/about'),
        'site_id' => $siteDomain->site_id,
        'site_domain_id' => $siteDomain->getKey(),
        'language_id' => $siteDomain->language_id,
        'cache_path' => '../outside.html',
        'error_cache_path' => resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $siteDomain, error: true),
        'reason' => 'test',
        'status' => StaleCachedUrl::STATUS_PENDING,
    ]);

    expect(ProcessStaleHtmlCacheAction::run(1))->toBe(1)
        ->and(file_exists(Storage::disk('page_cache')->path('../outside.html')))->toBeFalse()
        ->and(Storage::disk('page_cache')->allFiles())->toBe([])
        ->and($staleCachedUrl->refresh()->status)->toBe(StaleCachedUrl::STATUS_FAILED)
        ->and($staleCachedUrl->last_error)->toContain('cache path was invalid');
});

it('blocks unsafe public html during stale refresh and keeps the old cache file', function (): void {
    Storage::fake('page_cache');

    $siteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => null,
    ]);
    $url = 'https://example.test/about';
    $cachePath = resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $siteDomain);

    Storage::disk('page_cache')->put($cachePath, 'old cached page');
    Route::get('/about', fn (): mixed => response('<div data-capell-editor="1"></div>', 200, ['Content-Type' => 'text/html']));

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

    ProcessStaleHtmlCacheAction::run(1);

    expect(Storage::disk('page_cache')->get($cachePath))->toBe('old cached page')
        ->and($staleCachedUrl->refresh()->status)->toBe(StaleCachedUrl::STATUS_FAILED)
        ->and($staleCachedUrl->last_error)->toContain('not cacheable');
});

it('uses middleware cacheability rules during stale refresh', function (): void {
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
    Route::get('/about', function (): mixed {
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
        'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl($url), $siteDomain->site_id, $siteDomain->getKey(), '/about'),
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
        ->and($staleCachedUrl->last_error)->toContain('not cacheable');
});

it('deletes obsolete stale cache files and indexed urls when the domain no longer resolves', function (): void {
    Storage::fake('page_cache');

    $page = Page::factory()->withTranslations()->create();
    $url = 'https://obsolete.example.test/about';
    $urlHash = CachedModelUrl::hashUrl($url);
    $cachePath = 'obsolete/about.html';
    $errorCachePath = 'obsolete/about.404.html';

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

    RefreshCachedUrlAtomicallyAction::run($staleCachedUrl);

    expect(Storage::disk('page_cache')->exists($cachePath))->toBeFalse()
        ->and(Storage::disk('page_cache')->exists($errorCachePath))->toBeFalse()
        ->and(CachedModelUrl::query()->where('url_hash', $urlHash)->exists())->toBeFalse();
});

it('refreshes missing pages into the error cache', function (): void {
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
    $url = 'https://example.test/missing';
    $cachePath = resolve(HtmlCachePathResolver::class)->pathForUrl('/missing', $siteDomain);
    $errorCachePath = resolve(HtmlCachePathResolver::class)->pathForUrl('/missing', $siteDomain, error: true);

    bindHtmlCacheFrontendContext($page);
    Storage::disk('page_cache')->put($cachePath, 'old cached page');
    Route::get('/missing', fn (): mixed => response('fresh missing page', 404, ['Content-Type' => 'text/html']));

    $staleCachedUrl = StaleCachedUrl::query()->create([
        'url' => $url,
        'url_hash' => CachedModelUrl::hashUrl($url),
        'path' => '/missing',
        'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl($url), $siteDomain->site_id, $siteDomain->getKey(), '/missing'),
        'site_id' => $siteDomain->site_id,
        'site_domain_id' => $siteDomain->getKey(),
        'language_id' => $siteDomain->language_id,
        'cache_path' => $cachePath,
        'error_cache_path' => $errorCachePath,
        'reason' => 'test',
        'status' => StaleCachedUrl::STATUS_PENDING,
    ]);

    expect(ProcessStaleHtmlCacheAction::run(1))->toBe(1)
        ->and(Storage::disk('page_cache')->get($errorCachePath))->toBe('fresh missing page')
        ->and($staleCachedUrl->refresh()->status)->toBe(StaleCachedUrl::STATUS_PROCESSED);
});

it('preserves the old cache file when stale refresh cache writes are disabled', function (): void {
    Storage::fake('page_cache');
    config()->set('capell-html-cache.write_enabled', false);

    $siteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => null,
    ]);
    $page = Page::factory()
        ->recycle($siteDomain->site)
        ->withTranslations()
        ->create();
    $url = 'https://example.test/write-disabled';
    $cachePath = resolve(HtmlCachePathResolver::class)->pathForUrl('/write-disabled', $siteDomain);

    bindHtmlCacheFrontendContext($page);
    Storage::disk('page_cache')->put($cachePath, 'old cached page');
    Route::get('/write-disabled', fn (): mixed => response('fresh cached page', 200, ['Content-Type' => 'text/html']));

    $staleCachedUrl = StaleCachedUrl::query()->create([
        'url' => $url,
        'url_hash' => CachedModelUrl::hashUrl($url),
        'path' => '/write-disabled',
        'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl($url), $siteDomain->site_id, $siteDomain->getKey(), '/write-disabled'),
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
    Route::get('/about', fn (): mixed => response('fresh cached page', 200, ['Content-Type' => 'text/html']));

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
        'status' => StaleCachedUrl::STATUS_FAILED,
        'attempts' => 1,
        'failed_at' => now()->subMinutes(6),
        'last_error' => 'temporary failure',
    ]);

    expect(ProcessStaleHtmlCacheAction::run(1))->toBe(1)
        ->and(Storage::disk('page_cache')->get($cachePath))->toBe('fresh cached page')
        ->and($staleCachedUrl->refresh()->status)->toBe(StaleCachedUrl::STATUS_PROCESSED)
        ->and($staleCachedUrl->attempts)->toBe(0);
});

it('does not claim actively processing stale cache rows', function (): void {
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
    Route::get('/about', fn (): mixed => response('fresh cached page', 200, ['Content-Type' => 'text/html']));

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
        'status' => StaleCachedUrl::STATUS_PROCESSING,
        'attempts' => 1,
    ]);

    expect(ProcessStaleHtmlCacheAction::run(1))->toBe(0)
        ->and(Storage::disk('page_cache')->get($cachePath))->toBe('old cached page')
        ->and($staleCachedUrl->refresh()->status)->toBe(StaleCachedUrl::STATUS_PROCESSING)
        ->and($staleCachedUrl->attempts)->toBe(1);
});

it('keeps a new stale mark pending when a model changes during stale refresh', function (): void {
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
    Route::get('/about', function () use ($url): mixed {
        MarkCachedUrlStaleAction::run($url, 'changed_during_refresh');

        return response('stale in-flight html', 200, ['Content-Type' => 'text/html']);
    });

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

    expect(ProcessStaleHtmlCacheAction::run(1))->toBe(1)
        ->and(Storage::disk('page_cache')->get($cachePath))->toBe('old cached page')
        ->and($staleCachedUrl->refresh()->status)->toBe(StaleCachedUrl::STATUS_PENDING)
        ->and($staleCachedUrl->claim_token)->toBeNull()
        ->and($staleCachedUrl->reason)->toBe('changed_during_refresh');
});

it('prevents a late stale refresh worker from writing after the row is reclaimed', function (): void {
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
    Route::get('/about', fn (): mixed => response('late worker html', 200, ['Content-Type' => 'text/html']));

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

    $siteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => null,
    ]);
    $url = 'https://example.test/about';
    $cachePath = resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $siteDomain);

    Storage::disk('page_cache')->put($cachePath, 'old cached page');
    Route::get('/about', fn (): mixed => response('missing', 404, ['Content-Type' => 'text/html']));

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

it('marks repeatedly failing stale cache rows exhausted after the configured max attempts', function (): void {
    Storage::fake('page_cache');
    config()->set('capell-html-cache.invalidation.max_attempts', 2);

    $siteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => null,
    ]);
    $url = 'https://example.test/about';
    $cachePath = resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $siteDomain);

    Storage::disk('page_cache')->put($cachePath, 'old cached page');
    Route::get('/about', fn (): mixed => response('broken', 500, ['Content-Type' => 'text/html']));

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
        'status' => StaleCachedUrl::STATUS_FAILED,
        'attempts' => 1,
        'failed_at' => now()->subMinutes(6),
    ]);

    expect(ProcessStaleHtmlCacheAction::run(1))->toBe(1)
        ->and($staleCachedUrl->refresh()->status)->toBe(StaleCachedUrl::STATUS_EXHAUSTED)
        ->and($staleCachedUrl->attempts)->toBe(2)
        ->and($staleCachedUrl->claim_token)->toBeNull()
        ->and(ProcessStaleHtmlCacheAction::run(1))->toBe(0);
});

it('resets the retry budget when a failing stale url is marked stale again', function (): void {
    Storage::fake('page_cache');
    config()->set('capell-html-cache.invalidation.max_attempts', 2);

    $siteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => null,
    ]);
    $url = 'https://example.test/about';
    $cachePath = resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $siteDomain);

    Storage::disk('page_cache')->put($cachePath, 'old cached page');
    Route::get('/about', fn (): mixed => response('broken', 500, ['Content-Type' => 'text/html']));

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
        'status' => StaleCachedUrl::STATUS_FAILED,
        'attempts' => 1,
        'failed_at' => now()->subMinutes(6),
    ]);

    MarkCachedUrlStaleAction::run($url, 'changed_again');

    expect($staleCachedUrl->refresh()->attempts)->toBe(0)
        ->and(ProcessStaleHtmlCacheAction::run(1))->toBe(1)
        ->and($staleCachedUrl->refresh()->status)->toBe(StaleCachedUrl::STATUS_FAILED)
        ->and($staleCachedUrl->attempts)->toBe(1);
});

it('processes fresh pending stale urls before retrying older failed rows', function (): void {
    Storage::fake('page_cache');
    Cache::forget('capell-html-cache:stale-refresh:single-item-source');
    config()->set('capell-html-cache.invalidation.retry_backoff_minutes', 5);

    $siteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => null,
    ]);
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

    Route::get('/old', fn (): mixed => response('broken', 500, ['Content-Type' => 'text/html']));
    Route::get('/new', fn (): mixed => response('fresh pending page', 200, ['Content-Type' => 'text/html']));
    Route::get('/new-two', fn (): mixed => response('fresh second pending page', 200, ['Content-Type' => 'text/html']));

    $oldFailedStaleCachedUrl = StaleCachedUrl::query()->create([
        'url' => 'https://example.test/old',
        'url_hash' => CachedModelUrl::hashUrl('https://example.test/old'),
        'path' => '/old',
        'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl('https://example.test/old'), $siteDomain->site_id, $siteDomain->getKey(), '/old'),
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
        'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl('https://example.test/new'), $siteDomain->site_id, $siteDomain->getKey(), '/new'),
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
        'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl('https://example.test/new-two'), $siteDomain->site_id, $siteDomain->getKey(), '/new-two'),
        'site_id' => $siteDomain->site_id,
        'site_domain_id' => $siteDomain->getKey(),
        'language_id' => $siteDomain->language_id,
        'cache_path' => resolve(HtmlCachePathResolver::class)->pathForUrl('/new-two', $siteDomain),
        'error_cache_path' => resolve(HtmlCachePathResolver::class)->pathForUrl('/new-two', $siteDomain, error: true),
        'reason' => 'test',
        'status' => StaleCachedUrl::STATUS_PENDING,
    ]);

    expect(ProcessStaleHtmlCacheAction::run(1))->toBe(1)
        ->and($newPendingStaleCachedUrl->refresh()->status)->toBe(StaleCachedUrl::STATUS_PROCESSED)
        ->and($oldFailedStaleCachedUrl->refresh()->status)->toBe(StaleCachedUrl::STATUS_FAILED)
        ->and($secondPendingStaleCachedUrl->refresh()->status)->toBe(StaleCachedUrl::STATUS_PENDING)
        ->and(ProcessStaleHtmlCacheAction::run(1))->toBe(1)
        ->and($oldFailedStaleCachedUrl->refresh()->attempts)->toBe(2)
        ->and($oldFailedStaleCachedUrl->status)->toBe(StaleCachedUrl::STATUS_FAILED)
        ->and($secondPendingStaleCachedUrl->refresh()->status)->toBe(StaleCachedUrl::STATUS_PENDING);
});

it('processes retryable stale urls alongside pending batch capacity', function (): void {
    Storage::fake('page_cache');
    config()->set('capell-html-cache.invalidation.retry_backoff_minutes', 5);

    $siteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => null,
    ]);
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

    Route::get('/old', fn (): mixed => response('still broken', 500, ['Content-Type' => 'text/html']));
    Route::get('/new-one', fn (): mixed => response('fresh first pending page', 200, ['Content-Type' => 'text/html']));
    Route::get('/new-two', fn (): mixed => response('fresh second pending page', 200, ['Content-Type' => 'text/html']));

    $oldFailedStaleCachedUrl = StaleCachedUrl::query()->create([
        'url' => 'https://example.test/old',
        'url_hash' => CachedModelUrl::hashUrl('https://example.test/old'),
        'path' => '/old',
        'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl('https://example.test/old'), $siteDomain->site_id, $siteDomain->getKey(), '/old'),
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
        'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl('https://example.test/new-one'), $siteDomain->site_id, $siteDomain->getKey(), '/new-one'),
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
        'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl('https://example.test/new-two'), $siteDomain->site_id, $siteDomain->getKey(), '/new-two'),
        'site_id' => $siteDomain->site_id,
        'site_domain_id' => $siteDomain->getKey(),
        'language_id' => $siteDomain->language_id,
        'cache_path' => resolve(HtmlCachePathResolver::class)->pathForUrl('/new-two', $siteDomain),
        'error_cache_path' => resolve(HtmlCachePathResolver::class)->pathForUrl('/new-two', $siteDomain, error: true),
        'reason' => 'test',
        'status' => StaleCachedUrl::STATUS_PENDING,
    ]);

    expect(ProcessStaleHtmlCacheAction::run(2))->toBe(2)
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

    $siteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => null,
    ]);
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
        Route::get($path, fn (): mixed => response('fresh ' . $path, 200, ['Content-Type' => 'text/html']));
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

    expect(ProcessStaleHtmlCacheAction::run())->toBe(3)
        ->and($pending->refresh()->status)->toBe(StaleCachedUrl::STATUS_PROCESSED)
        ->and($failed->refresh()->status)->toBe(StaleCachedUrl::STATUS_PROCESSED)
        ->and($timedOut->refresh()->status)->toBe(StaleCachedUrl::STATUS_PROCESSED)
        ->and(ProcessStaleHtmlCacheAction::run(2))->toBe(0);
});

it('alternates failed and timed out processing rows in single item retry batches', function (): void {
    Storage::fake('page_cache');
    Cache::forget('capell-html-cache:stale-refresh:single-retryable-source');
    config()->set('capell-html-cache.invalidation.processing_timeout_minutes', 15);
    config()->set('capell-html-cache.invalidation.retry_backoff_minutes', 5);

    $siteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => null,
    ]);
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

    Route::get('/failed', fn (): mixed => response('still broken', 500, ['Content-Type' => 'text/html']));
    Route::get('/timed-out', fn (): mixed => response('fresh timed out page', 200, ['Content-Type' => 'text/html']));

    $failedStaleCachedUrl = StaleCachedUrl::query()->create([
        'url' => 'https://example.test/failed',
        'url_hash' => CachedModelUrl::hashUrl('https://example.test/failed'),
        'path' => '/failed',
        'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl('https://example.test/failed'), $siteDomain->site_id, $siteDomain->getKey(), '/failed'),
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
        'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl('https://example.test/timed-out'), $siteDomain->site_id, $siteDomain->getKey(), '/timed-out'),
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

    expect(ProcessStaleHtmlCacheAction::run(1))->toBe(1)
        ->and($failedStaleCachedUrl->refresh()->attempts)->toBe(2)
        ->and($failedStaleCachedUrl->status)->toBe(StaleCachedUrl::STATUS_FAILED)
        ->and($timedOutStaleCachedUrl->refresh()->status)->toBe(StaleCachedUrl::STATUS_PROCESSING)
        ->and(ProcessStaleHtmlCacheAction::run(1))->toBe(1)
        ->and($timedOutStaleCachedUrl->refresh()->status)->toBe(StaleCachedUrl::STATUS_PROCESSED)
        ->and(Storage::disk('page_cache')->get(resolve(HtmlCachePathResolver::class)->pathForUrl('/timed-out', $siteDomain)))->toBe('fresh timed out page');
});

it('processes stale cache command with the requested limit', function (): void {
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
    bindHtmlCacheFrontendContext($page);

    foreach (['/one', '/two'] as $path) {
        $url = 'https://example.test' . $path;
        $cachePath = resolve(HtmlCachePathResolver::class)->pathForUrl($path, $siteDomain);

        Storage::disk('page_cache')->put($cachePath, 'old cached page');
        Route::get($path, fn (): mixed => response('fresh cached page', 200, ['Content-Type' => 'text/html']));
        StaleCachedUrl::query()->create([
            'url' => $url,
            'url_hash' => CachedModelUrl::hashUrl($url),
            'path' => $path,
            'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl($url), $siteDomain->site_id, $siteDomain->getKey(), $path),
            'site_id' => $siteDomain->site_id,
            'site_domain_id' => $siteDomain->getKey(),
            'language_id' => $siteDomain->language_id,
            'cache_path' => $cachePath,
            'error_cache_path' => resolve(HtmlCachePathResolver::class)->pathForUrl($path, $siteDomain, error: true),
            'reason' => 'test',
            'status' => StaleCachedUrl::STATUS_PENDING,
        ]);
    }

    $this->artisan('capell:html-cache:process-stale', ['--limit' => 1])
        ->expectsOutput('Processed 1 stale HTML cache URL(s).')
        ->assertSuccessful();

    expect(StaleCachedUrl::query()->where('status', StaleCachedUrl::STATUS_PROCESSED)->count())->toBe(1)
        ->and(StaleCachedUrl::query()->where('status', StaleCachedUrl::STATUS_PENDING)->count())->toBe(1);
});

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
