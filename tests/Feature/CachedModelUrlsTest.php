<?php

declare(strict_types=1);

use Capell\Admin\Data\Bridges\AdminBridgeContextData;
use Capell\Admin\Enums\AdminSurfaceContributionType;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Pages\SiteHealthPage;
use Capell\Admin\Support\Bridges\AdminBridgeRegistrar;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Concerns\HasSitePermissions;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Theme;
use Capell\Core\Models\Translation;
use Capell\Frontend\Actions\Performance\RecordExtensionRenderContributionAction;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Facades\Frontend;
use Capell\Frontend\Support\CapellFrontendContext;
use Capell\HtmlCache\Actions\BuildCachedModelUrlDiagnosticsAction;
use Capell\HtmlCache\Actions\BuildCacheMapOverviewAction;
use Capell\HtmlCache\Actions\BuildHtmlCachePublicOutputSafetyDiagnosticsAction;
use Capell\HtmlCache\Actions\ClearCachedUrlAction;
use Capell\HtmlCache\Actions\ClearCachedUrlsForModelAction;
use Capell\HtmlCache\Actions\EnsureHtmlCachePermissionsAction;
use Capell\HtmlCache\Actions\ListCacheMapResourceOptionsAction;
use Capell\HtmlCache\Actions\MarkCachedUrlsForModelStaleAction;
use Capell\HtmlCache\Actions\MarkCachedUrlStaleAction;
use Capell\HtmlCache\Actions\ProcessStaleHtmlCacheAction;
use Capell\HtmlCache\Actions\RecordCachedModelUrlsAction;
use Capell\HtmlCache\Actions\RefreshCachedUrlAtomicallyAction;
use Capell\HtmlCache\Bridges\HtmlCacheAdminBridge;
use Capell\HtmlCache\Enums\HtmlCachePermission;
use Capell\HtmlCache\Filament\Resources\CachedModelUrls\CachedModelUrlResource;
use Capell\HtmlCache\Jobs\RegisterCachedModelUrlsJob;
use Capell\HtmlCache\Livewire\SiteHealthCacheMap;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Models\StaleCachedUrl;
use Capell\HtmlCache\Support\Admin\HtmlCacheSiteHealthWidget;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Capell\HtmlCache\Support\Cache\HtmlCacheStore;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Capell\Tests\Fixtures\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\artisan;

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(HtmlCacheTestCase::class);

function htmlCacheMapTestComponent(int $siteId, string $modelType): mixed
{
    return Livewire::test(SiteHealthCacheMap::class, ['siteId' => $siteId])
        ->set('selectedModelType', $modelType);
}

function bindHtmlCacheFrontendContext(?Pageable $page = null): void
{
    config()->set('capell-html-cache.enabled', true);

    app()->instance(CapellFrontendContext::class, new CapellFrontendContext(
        new class($page) implements FrontendContextReader
        {
            public function __construct(private readonly ?Pageable $page) {}

            public function site(): ?Site
            {
                return null;
            }

            public function language(): ?Language
            {
                return null;
            }

            public function page(): ?Pageable
            {
                return $this->page;
            }

            public function layout(): ?Layout
            {
                return null;
            }

            public function theme(): ?Theme
            {
                return null;
            }

            /**
             * @return array<string, mixed>
             */
            public function params(): array
            {
                return [];
            }

            public function slug(): ?string
            {
                return null;
            }

            public function isError(): bool
            {
                return false;
            }

            public function setFrontendData(string $key, mixed $value): self
            {
                return $this;
            }

            public function getFrontendData(?string $key = null): mixed
            {
                return $key === null ? [] : null;
            }
        },
    ));
    Frontend::clearResolvedInstance(CapellFrontendContext::class);
}

it('records rendered models against a cached url and removes stale model links', function (): void {
    [$siteDomain, $page] = EloquentModel::withoutEvents(function (): array {
        $siteDomain = SiteDomain::factory()->create([
            'scheme' => 'https',
            'domain' => 'example.test',
            'path' => null,
        ]);

        return [
            $siteDomain,
            Page::factory()
                ->recycle($siteDomain->site)
                ->withTranslations()
                ->create(),
        ];
    });
    $translation = $page->translations()->where('language_id', $siteDomain->language_id)->first();

    expect($translation)->toBeInstanceOf(Translation::class);
    $url = 'https://example.test/about';

    RecordCachedModelUrlsAction::run($url, [
        $page->getMorphClass() => [$page->getKey()],
        $translation->getMorphClass() => [$translation->getKey()],
    ]);

    expect(CachedModelUrl::query()->where('url', $url)->count())->toBe(2)
        ->and(CachedModelUrl::query()->where('url', $url)->first())
        ->site_id->toBe($siteDomain->site_id)
        ->site_domain_id->toBe($siteDomain->getKey())
        ->language_id->toBe($siteDomain->language_id)
        ->path->toBe('/about');

    RecordCachedModelUrlsAction::run($url, [
        $page->getMorphClass() => [$page->getKey()],
    ]);

    expect(CachedModelUrl::query()->where('url', $url)->count())->toBe(1)
        ->and(CachedModelUrl::query()->where('url', $url)->first())
        ->cacheable_type->toBe($page->getMorphClass())
        ->cacheable_id->toBe($page->getKey());
});

it('configures cached model url registration retries and backoff', function (): void {
    $job = new RegisterCachedModelUrlsJob('https://example.test/about', []);

    expect($job->tries)->toBe(5)
        ->and($job->backoff())->toBe([1, 5, 15, 30]);
});

it('clears cached files and table rows for a url', function (): void {
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
    $errorCachePath = resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $siteDomain, error: true);

    Storage::disk('page_cache')->put($cachePath, 'cached page');
    Storage::disk('page_cache')->put($errorCachePath, 'cached error page');
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

    expect(ClearCachedUrlAction::run($url))->toBeTrue()
        ->and(Storage::disk('page_cache')->exists($cachePath))->toBeFalse()
        ->and(Storage::disk('page_cache')->exists($errorCachePath))->toBeFalse()
        ->and(CachedModelUrl::query()->where('url', $url)->exists())->toBeFalse();
});

it('marks model cached urls stale in scheduled invalidation mode without deleting the current cache', function (): void {
    Storage::fake('page_cache');
    config()->set('capell-html-cache.invalidation.mode', 'scheduled');

    [$siteDomain, $page] = EloquentModel::withoutEvents(function (): array {
        $siteDomain = SiteDomain::factory()->create([
            'scheme' => 'https',
            'domain' => 'example.test',
            'path' => null,
        ]);

        return [
            $siteDomain,
            Page::factory()
                ->recycle($siteDomain->site)
                ->withTranslations()
                ->create(),
        ];
    });
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

    $page->update(['name' => 'Updated page']);

    expect(Storage::disk('page_cache')->exists($cachePath))->toBeTrue()
        ->and(Storage::disk('page_cache')->get($cachePath))->toBe('old cached page')
        ->and(CachedModelUrl::query()->where('url', $url)->exists())->toBeTrue()
        ->and(StaleCachedUrl::query()->where('url', $url)->first())
        ->not->toBeNull()
        ->status->toBe(StaleCachedUrl::STATUS_PENDING)
        ->cache_path->toBe($cachePath);
});

it('marks cached urls stale for a model from the cache map', function (): void {
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
    $urlHash = CachedModelUrl::hashUrl($url);

    CachedModelUrl::query()->create([
        'url' => $url,
        'url_hash' => $urlHash,
        'path' => '/about',
        'site_id' => $siteDomain->site_id,
        'site_domain_id' => $siteDomain->getKey(),
        'language_id' => $siteDomain->language_id,
        'cacheable_type' => $page->getMorphClass(),
        'cacheable_id' => $page->getKey(),
        'cached_at' => now(),
        'last_seen_at' => now(),
    ]);
    StaleCachedUrl::query()->create([
        'url' => $url,
        'url_hash' => $urlHash,
        'path' => '/about',
        'stale_key' => StaleCachedUrl::staleKey($urlHash, $siteDomain->site_id, $siteDomain->getKey(), '/about'),
        'site_id' => $siteDomain->site_id,
        'site_domain_id' => $siteDomain->getKey(),
        'language_id' => $siteDomain->language_id,
        'reason' => 'previous_failure',
        'status' => StaleCachedUrl::STATUS_FAILED,
        'attempts' => 4,
        'failed_at' => now()->subMinutes(6),
    ]);

    expect(MarkCachedUrlsForModelStaleAction::run($page))->toBe(1)
        ->and(StaleCachedUrl::query()->where('url', $url)->first())
        ->status->toBe(StaleCachedUrl::STATUS_PENDING)
        ->attempts->toBe(0);
});

it('clears only the selected site scope when clearing a cached model url row', function (): void {
    Storage::fake('page_cache');

    $firstSiteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'first.test',
        'path' => null,
    ]);
    $secondSiteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'second.test',
        'path' => null,
    ]);
    $firstPage = Page::factory()
        ->recycle($firstSiteDomain->site)
        ->withTranslations()
        ->create();
    $secondPage = Page::factory()
        ->recycle($secondSiteDomain->site)
        ->withTranslations()
        ->create();
    $url = 'https://shared.test/about';
    $firstCachePath = resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $firstSiteDomain);
    $secondCachePath = resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $secondSiteDomain);

    Storage::disk('page_cache')->put($firstCachePath, 'first cached page');
    Storage::disk('page_cache')->put($secondCachePath, 'second cached page');

    $firstCachedModelUrl = CachedModelUrl::query()->create([
        'url' => $url,
        'url_hash' => CachedModelUrl::hashUrl($url),
        'path' => '/about',
        'site_id' => $firstSiteDomain->site_id,
        'site_domain_id' => $firstSiteDomain->getKey(),
        'language_id' => $firstSiteDomain->language_id,
        'cacheable_type' => $firstPage->getMorphClass(),
        'cacheable_id' => $firstPage->getKey(),
        'cached_at' => now(),
        'last_seen_at' => now(),
    ]);
    $secondCachedModelUrl = CachedModelUrl::query()->create([
        'url' => $url,
        'url_hash' => CachedModelUrl::hashUrl($url),
        'path' => '/about',
        'site_id' => $secondSiteDomain->site_id,
        'site_domain_id' => $secondSiteDomain->getKey(),
        'language_id' => $secondSiteDomain->language_id,
        'cacheable_type' => $secondPage->getMorphClass(),
        'cacheable_id' => $secondPage->getKey(),
        'cached_at' => now(),
        'last_seen_at' => now(),
    ]);

    expect(ClearCachedUrlAction::run($firstCachedModelUrl))->toBeTrue()
        ->and(Storage::disk('page_cache')->exists($firstCachePath))->toBeFalse()
        ->and(Storage::disk('page_cache')->exists($secondCachePath))->toBeTrue()
        ->and(CachedModelUrl::query()->whereKey($firstCachedModelUrl->getKey())->exists())->toBeFalse()
        ->and(CachedModelUrl::query()->whereKey($secondCachedModelUrl->getKey())->exists())->toBeTrue();
});

it('registers html cache middleware into the real frontend route middleware stack', function (): void {
    $route = Route::getRoutes()->getByName('capell-frontend.page');

    expect($route)->not->toBeNull();

    $middleware = $route->gatherMiddleware();

    expect($middleware)
        ->toContain('frontend.cache')
        ->toContain('frontend.model_events')
        ->toContain('frontend.no_session_cookies_on_cache')
        ->and(array_search('frontend.cache', $middleware, true))
        ->toBeGreaterThan(array_search('web', $middleware, true))
        ->and(array_search('frontend.cache', $middleware, true))
        ->toBeLessThan(array_search('frontend.resolve', $middleware, true))
        ->and(array_search('frontend.model_events', $middleware, true))
        ->toBeGreaterThan(array_search('frontend.anonymous_cacheable_render', $middleware, true));
});

it('clears stale cached url rows when the url no longer resolves to a site domain', function (): void {
    $siteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => null,
    ]);
    $page = Page::factory()
        ->recycle($siteDomain->site)
        ->withTranslations()
        ->create();
    $url = 'https://old-domain.test/about';

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

    expect(ClearCachedUrlAction::run($url))->toBeFalse()
        ->and(CachedModelUrl::query()->where('url', $url)->exists())->toBeFalse();
});

it('clears historical cached files from stored rows when the url no longer resolves', function (): void {
    Storage::fake('page_cache');

    $siteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'old-domain.test',
        'path' => null,
    ]);
    $page = Page::factory()
        ->recycle($siteDomain->site)
        ->withTranslations()
        ->create();
    $url = 'https://missing-domain.test/about';
    $cachePath = resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $siteDomain);

    Storage::disk('page_cache')->put($cachePath, 'stale cached page');
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

    expect(ClearCachedUrlAction::run($url))->toBeFalse()
        ->and(Storage::disk('page_cache')->exists($cachePath))->toBeFalse()
        ->and(CachedModelUrl::query()->where('url', $url)->exists())->toBeFalse();
});

it('clears cached urls for a deleted model without restoring the model from the queue', function (): void {
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
    $morphClass = $page->getMorphClass();
    $pageKey = (int) $page->getKey();
    $url = 'https://example.test/deleted-model';

    CachedModelUrl::query()->create([
        'url' => $url,
        'url_hash' => CachedModelUrl::hashUrl($url),
        'path' => '/deleted-model',
        'site_id' => $siteDomain->site_id,
        'site_domain_id' => $siteDomain->getKey(),
        'language_id' => $siteDomain->language_id,
        'cacheable_type' => $morphClass,
        'cacheable_id' => $pageKey,
        'cached_at' => now(),
        'last_seen_at' => now(),
    ]);

    DB::table('pages')->where('id', $pageKey)->delete();

    expect(ClearCachedUrlsForModelAction::dispatchSync($morphClass, $pageKey))->toBe(0)
        ->and(CachedModelUrl::query()->where('url', $url)->exists())->toBeFalse();
});

it('clears all cached urls when a site domain changes', function (): void {
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

    Storage::disk('page_cache')->put($cachePath, 'cached page');
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
    app()->terminate();

    expect(Storage::disk('page_cache')->exists($cachePath))->toBeFalse()
        ->and(CachedModelUrl::query()->where('url', $url)->exists())->toBeFalse();
});

it('marks indexed urls stale for broad invalidation in scheduled mode', function (): void {
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
        'cacheable_type' => $page->getMorphClass(),
        'cacheable_id' => $page->getKey(),
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

    artisan('capell:html-cache:process-stale', ['--limit' => 1])
        ->expectsOutput('Processed 1 stale HTML cache URL(s).')
        ->assertSuccessful();

    expect(StaleCachedUrl::query()->where('status', StaleCachedUrl::STATUS_PROCESSED)->count())->toBe(1)
        ->and(StaleCachedUrl::query()->where('status', StaleCachedUrl::STATUS_PENDING)->count())->toBe(1);
});

it('reports unsafe cached public html through package diagnostics', function (): void {
    Storage::fake('page_cache');
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    test()->actingAs($user);

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

    Storage::disk('page_cache')->put($cachePath, '<div data-capell-editor="1"></div>');
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

    $checks = BuildHtmlCachePublicOutputSafetyDiagnosticsAction::run();

    expect($checks)->toHaveCount(1)
        ->and($checks[0]->status)->toBe('red')
        ->and($checks[0]->detail)->toContain('data-capell-editor');
});

it('scopes cached public html diagnostics to the selected site', function (): void {
    Storage::fake('page_cache');
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    test()->actingAs($user);

    $firstSiteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'first.test',
        'path' => null,
    ]);
    $secondSiteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'second.test',
        'path' => null,
    ]);
    $firstPage = Page::factory()
        ->recycle($firstSiteDomain->site)
        ->withTranslations()
        ->create();
    $secondPage = Page::factory()
        ->recycle($secondSiteDomain->site)
        ->withTranslations()
        ->create();

    Storage::disk('page_cache')->put(
        resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $firstSiteDomain),
        '<main>safe</main>',
    );
    Storage::disk('page_cache')->put(
        resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $secondSiteDomain),
        '<div data-capell-editor="1"></div>',
    );

    foreach ([[$firstSiteDomain, $firstPage], [$secondSiteDomain, $secondPage]] as [$siteDomain, $page]) {
        CachedModelUrl::query()->create([
            'url' => sprintf('https://%s/about', $siteDomain->domain),
            'url_hash' => CachedModelUrl::hashUrl(sprintf('https://%s/about', $siteDomain->domain)),
            'path' => '/about',
            'site_id' => $siteDomain->site_id,
            'site_domain_id' => $siteDomain->getKey(),
            'language_id' => $siteDomain->language_id,
            'cacheable_type' => $page->getMorphClass(),
            'cacheable_id' => $page->getKey(),
            'cached_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    $checks = BuildHtmlCachePublicOutputSafetyDiagnosticsAction::run((int) $firstSiteDomain->site_id);

    expect($checks)->toHaveCount(1)
        ->and($checks[0]->status)->toBe('green');
});

it('does not inspect path-based cached public html outside the selected site', function (): void {
    Storage::fake('page_cache');
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    test()->actingAs($user);

    $firstSiteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => '/uk',
    ]);
    $secondSiteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => '/fr',
    ]);

    Storage::disk('page_cache')->put(
        resolve(HtmlCachePathResolver::class)->pathForUrl('/', $firstSiteDomain),
        '<main>safe</main>',
    );
    Storage::disk('page_cache')->put(
        resolve(HtmlCachePathResolver::class)->pathForUrl('/', $secondSiteDomain),
        '<div data-capell-editor="1"></div>',
    );

    $checks = BuildHtmlCachePublicOutputSafetyDiagnosticsAction::run((int) $firstSiteDomain->site_id);

    expect(collect($checks)->pluck('status')->all())->not->toContain('red')
        ->and(collect($checks)->pluck('detail')->implode(' '))->not->toContain('data-capell-editor');
});

it('installs html cache permissions', function (): void {
    EnsureHtmlCachePermissionsAction::run();

    expect(Permission::query()
        ->whereIn('name', HtmlCachePermission::names())
        ->count())->toBe(count(HtmlCachePermission::cases()));
});

it('reports unsafe unindexed cached public html files', function (): void {
    Storage::fake('page_cache');
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    test()->actingAs($user);

    $siteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => null,
    ]);
    $orphanCachePath = resolve(HtmlCachePathResolver::class)->pathForUrl('/orphan', $siteDomain);

    Storage::disk('page_cache')->put($orphanCachePath, '<div data-capell-editor="1"></div>');

    $checks = BuildHtmlCachePublicOutputSafetyDiagnosticsAction::run((int) $siteDomain->site_id);

    expect(collect($checks)->pluck('status')->all())->toContain('amber', 'red')
        ->and(collect($checks)->pluck('detail')->implode(' '))->toContain('without cache index rows')
        ->and(collect($checks)->pluck('detail')->implode(' '))->toContain('data-capell-editor');
});

it('reports cached model url diagnostics for the selected site only', function (): void {
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    test()->actingAs($user);

    $firstSiteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'first.test',
        'path' => null,
    ]);
    $secondSiteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'second.test',
        'path' => null,
    ]);
    $firstPage = Page::factory()
        ->recycle($firstSiteDomain->site)
        ->withTranslations()
        ->create();
    $secondPage = Page::factory()
        ->recycle($secondSiteDomain->site)
        ->withTranslations()
        ->create();

    CachedModelUrl::query()->create([
        'url' => 'https://first.test/about',
        'url_hash' => CachedModelUrl::hashUrl('https://first.test/about'),
        'path' => '/about',
        'site_id' => $firstSiteDomain->site_id,
        'site_domain_id' => $firstSiteDomain->getKey(),
        'language_id' => $firstSiteDomain->language_id,
        'cacheable_type' => $firstPage->getMorphClass(),
        'cacheable_id' => $firstPage->getKey(),
        'cached_at' => now(),
        'last_seen_at' => now(),
    ]);
    CachedModelUrl::query()->create([
        'url' => 'https://second.test/about',
        'url_hash' => CachedModelUrl::hashUrl('https://second.test/about'),
        'path' => '/about',
        'site_id' => $secondSiteDomain->site_id,
        'site_domain_id' => $secondSiteDomain->getKey(),
        'language_id' => $secondSiteDomain->language_id,
        'cacheable_type' => $secondPage->getMorphClass(),
        'cacheable_id' => $secondPage->getKey(),
        'cached_at' => now(),
        'last_seen_at' => now(),
    ]);

    $checks = BuildCachedModelUrlDiagnosticsAction::run((int) $firstSiteDomain->site_id);

    expect($checks)->toHaveCount(1)
        ->and($checks[0]->status)->toBe('green')
        ->and($checks[0]->detail)->toContain('1 of 1');
});

it('reports when no cached model urls are tracked', function (): void {
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    test()->actingAs($user);

    $checks = BuildCachedModelUrlDiagnosticsAction::run();

    expect($checks)->toHaveCount(1)
        ->and($checks[0]->status)->toBe('amber')
        ->and($checks[0]->detail)->toBe(__('capell-html-cache::admin.no_cached_model_urls_tracked'));
});

it('exposes the selected site and cache map widget on site health', function (): void {
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    test()->actingAs($user);

    $siteDomain = SiteDomain::factory()->create();
    $page = resolve(SiteHealthPage::class);

    $page->mount();

    expect($page->selectedSiteId)->toBe($siteDomain->site_id)
        ->and($page->siteOptions())->toHaveKey($siteDomain->site_id)
        ->and(collect($page->siteHealthWidgets())->map(fn (object $widget): string => $widget->key())->all())->toContain('html-cache-map')
        ->and(resolve(HtmlCacheSiteHealthWidget::class)->component())->toBe('capell-html-cache.site-health-cache-map')
        ->and(view()->exists('capell-html-cache::livewire.site-health-cache-map'))->toBeTrue();
});

it('does not register the cached model urls resource as an admin page', function (): void {
    CapellAdmin::clearAdminSurfaceContributions();

    (new HtmlCacheAdminBridge)->register(
        new AdminBridgeRegistrar,
        AdminBridgeContextData::forPackage('capell-app/html-cache'),
    );

    expect(CapellAdmin::getAdminSurfaceContributions(AdminSurfaceContributionType::Resource))->toBe([]);
});

it('builds a cache map overview grouped by model and top resource impact', function (): void {
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    test()->actingAs($user);

    $siteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => null,
    ]);
    $sharedPage = Page::factory()
        ->recycle($siteDomain->site)
        ->withTranslations()
        ->create(['name' => 'Shared page']);
    $secondaryPage = Page::factory()
        ->recycle($siteDomain->site)
        ->withTranslations()
        ->create(['name' => 'Secondary page']);
    $translation = $sharedPage->translations()->first();

    expect($translation)->toBeInstanceOf(Translation::class);

    foreach ([
        ['https://example.test/about', $sharedPage],
        ['https://example.test/team', $sharedPage],
        ['https://example.test/about', $secondaryPage],
        ['https://example.test/about', $translation],
    ] as [$url, $cacheable]) {
        CachedModelUrl::query()->create([
            'url' => $url,
            'url_hash' => CachedModelUrl::hashUrl($url),
            'path' => str_replace('https://example.test', '', $url),
            'site_id' => $siteDomain->site_id,
            'site_domain_id' => $siteDomain->getKey(),
            'language_id' => $siteDomain->language_id,
            'cacheable_type' => $cacheable->getMorphClass(),
            'cacheable_id' => $cacheable->getKey(),
            'cached_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    $overview = BuildCacheMapOverviewAction::run((int) $siteDomain->site_id);

    expect($overview->totalUrls)->toBe(2)
        ->and($overview->totalDependencies)->toBe(4)
        ->and($overview->modelSummaries[0]->modelType)->toBe($sharedPage->getMorphClass())
        ->and($overview->modelSummaries[0]->urlCount)->toBe(2)
        ->and($overview->modelSummaries[0]->dependencyCount)->toBe(3)
        ->and(str_starts_with($overview->topResources[0]->label, 'Shared page'))->toBeTrue()
        ->and($overview->topResources[0]->urlCount)->toBe(2);
});

it('lists the top five cache map resources for the selected model and search', function (): void {
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    test()->actingAs($user);

    $siteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => null,
    ]);
    $otherSiteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'other.test',
        'path' => null,
    ]);
    $pages = collect(range(1, 6))
        ->map(fn (int $index): Page => Page::factory()
            ->recycle($siteDomain->site)
            ->withTranslations()
            ->create(['name' => $index === 6 ? 'Needle page' : 'Page ' . $index]));
    $otherSitePage = Page::factory()
        ->recycle($otherSiteDomain->site)
        ->withTranslations()
        ->create(['name' => 'Other site page']);

    $pages->each(function (Page $page, int $zeroBasedIndex) use ($siteDomain): void {
        foreach (range(1, 6 - $zeroBasedIndex) as $urlIndex) {
            $url = sprintf('https://example.test/page-%s-%s', $page->getKey(), $urlIndex);

            CachedModelUrl::query()->create([
                'url' => $url,
                'url_hash' => CachedModelUrl::hashUrl($url),
                'path' => str_replace('https://example.test', '', $url),
                'site_id' => $siteDomain->site_id,
                'site_domain_id' => $siteDomain->getKey(),
                'language_id' => $siteDomain->language_id,
                'cacheable_type' => $page->getMorphClass(),
                'cacheable_id' => $page->getKey(),
                'cached_at' => now(),
                'last_seen_at' => now(),
            ]);
        }
    });

    CachedModelUrl::query()->create([
        'url' => 'https://other.test/page',
        'url_hash' => CachedModelUrl::hashUrl('https://other.test/page'),
        'path' => '/page',
        'site_id' => $otherSiteDomain->site_id,
        'site_domain_id' => $otherSiteDomain->getKey(),
        'language_id' => $otherSiteDomain->language_id,
        'cacheable_type' => $otherSitePage->getMorphClass(),
        'cacheable_id' => $otherSitePage->getKey(),
        'cached_at' => now(),
        'last_seen_at' => now(),
    ]);

    $topOptions = ListCacheMapResourceOptionsAction::run($pages->first()->getMorphClass(), (int) $siteDomain->site_id);
    $searchOptions = ListCacheMapResourceOptionsAction::run($pages->first()->getMorphClass(), (int) $siteDomain->site_id, 'Needle');

    expect($topOptions)->toHaveCount(5)
        ->and(collect($topOptions)->pluck('label')->all())->not->toContain('Other site page')
        ->and(str_starts_with((string) $topOptions[0]->label, 'Page 1'))->toBeTrue()
        ->and($searchOptions)->toHaveCount(1)
        ->and(str_starts_with((string) $searchOptions[0]->label, 'Needle page'))->toBeTrue();
});

it('clears cache map rows through the table action for authorized actors', function (): void {
    Storage::fake('page_cache');

    $user = User::factory()->create();
    $user->assignRole('super_admin');

    test()->actingAs($user);

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

    Storage::disk('page_cache')->put($cachePath, 'cached page');
    $cachedModelUrl = CachedModelUrl::query()->create([
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

    htmlCacheMapTestComponent((int) $siteDomain->site_id, $page->getMorphClass())
        ->callTableAction('clear', record: (string) $cachedModelUrl->getKey());

    expect(Storage::disk('page_cache')->exists($cachePath))->toBeFalse()
        ->and(CachedModelUrl::query()->whereKey($cachedModelUrl->getKey())->exists())->toBeFalse();
});

it('hides cache map clear actions from actors without clear permission', function (): void {
    $user = new class extends User
    {
        use HasSitePermissions;

        protected $table = 'users';

        public function getMorphClass(): string
        {
            return User::class;
        }
    };
    $user->forceFill([
        'name' => 'Cache map viewer',
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
    ]);
    $user->save();

    test()->actingAs($user);

    [$siteDomain, $page] = EloquentModel::withoutEvents(function (): array {
        $siteDomain = SiteDomain::factory()->create([
            'scheme' => 'https',
            'domain' => 'example.test',
            'path' => null,
        ]);

        return [
            $siteDomain,
            Page::factory()
                ->recycle($siteDomain->site)
                ->withTranslations()
                ->create(),
        ];
    });
    $role = Role::findOrCreate('editor', 'web');
    DB::table('model_has_roles')->insert([
        'role_id' => $role->getKey(),
        'model_type' => $user->getMorphClass(),
        'model_id' => $user->getKey(),
        'team_id' => $siteDomain->site_id,
    ]);
    $cachedModelUrl = CachedModelUrl::query()->create([
        'url' => 'https://example.test/about',
        'url_hash' => CachedModelUrl::hashUrl('https://example.test/about'),
        'path' => '/about',
        'site_id' => $siteDomain->site_id,
        'site_domain_id' => $siteDomain->getKey(),
        'language_id' => $siteDomain->language_id,
        'cacheable_type' => $page->getMorphClass(),
        'cacheable_id' => $page->getKey(),
        'cached_at' => now(),
        'last_seen_at' => now(),
    ]);

    htmlCacheMapTestComponent((int) $siteDomain->site_id, $page->getMorphClass())
        ->assertCanSeeTableRecords([$cachedModelUrl])
        ->assertTableActionHidden('clear', record: (string) $cachedModelUrl->getKey());
});

it('denies cached model url resource rows when no actor is available', function (): void {
    $siteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => null,
    ]);
    $page = Page::factory()
        ->recycle($siteDomain->site)
        ->withTranslations()
        ->create();

    CachedModelUrl::query()->create([
        'url' => 'https://example.test/about',
        'url_hash' => CachedModelUrl::hashUrl('https://example.test/about'),
        'path' => '/about',
        'site_id' => $siteDomain->site_id,
        'site_domain_id' => $siteDomain->getKey(),
        'language_id' => $siteDomain->language_id,
        'cacheable_type' => $page->getMorphClass(),
        'cacheable_id' => $page->getKey(),
        'cached_at' => now(),
        'last_seen_at' => now(),
    ]);

    expect(CachedModelUrlResource::getEloquentQuery()->count())->toBe(0);
});

it('requires cache map view permission for the cached model url resource', function (): void {
    resolve(PermissionRegistrar::class)->setPermissionsTeamId(null);
    Permission::findOrCreate(HtmlCachePermission::ViewCachedModelUrls->value, 'web');

    $viewer = User::factory()->create();
    test()->actingAs($viewer);

    expect(CachedModelUrlResource::canAccess())->toBeFalse()
        ->and(CachedModelUrlResource::canViewAny())->toBeFalse();

    $viewer->givePermissionTo(HtmlCachePermission::ViewCachedModelUrls->value);
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(CachedModelUrlResource::canAccess())->toBeTrue()
        ->and(CachedModelUrlResource::canViewAny())->toBeTrue();
});

it('reports an amber diagnostic when cached html cannot be inspected', function (): void {
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    test()->actingAs($user);

    $siteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => null,
    ]);
    $page = Page::factory()
        ->recycle($siteDomain->site)
        ->withTranslations()
        ->create();

    CachedModelUrl::query()->create([
        'url' => 'https://example.test/about',
        'url_hash' => CachedModelUrl::hashUrl('https://example.test/about'),
        'path' => '/about',
        'site_id' => $siteDomain->site_id,
        'site_domain_id' => $siteDomain->getKey(),
        'language_id' => $siteDomain->language_id,
        'cacheable_type' => $page->getMorphClass(),
        'cacheable_id' => $page->getKey(),
        'cached_at' => now(),
        'last_seen_at' => now(),
    ]);

    app()->instance(HtmlCacheStore::class, new class
    {
        public function path(string $file): ?string
        {
            throw new RuntimeException('page_cache disk unavailable');
        }
    });

    $checks = BuildHtmlCachePublicOutputSafetyDiagnosticsAction::run();

    expect($checks)->toHaveCount(1)
        ->and($checks[0]->status)->toBe('amber')
        ->and($checks[0]->detail)->toBe(__('capell-html-cache::admin.cached_html_inspection_failed'))
        ->and($checks[0]->remediation)->toContain('page_cache disk unavailable');
});

it('does not let an older cached model url registration delete newer dependency rows', function (): void {
    $siteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => null,
    ]);
    $page = Page::factory()
        ->recycle($siteDomain->site)
        ->withTranslations()
        ->create();
    $translation = $page->translations()->where('language_id', $siteDomain->language_id)->first();
    $url = 'https://example.test/about';
    $olderSeenAt = CarbonImmutable::parse('2026-05-09 10:00:00');
    $newerSeenAt = CarbonImmutable::parse('2026-05-09 10:01:00');

    expect($translation)->toBeInstanceOf(Translation::class);

    RecordCachedModelUrlsAction::run($url, [
        $page->getMorphClass() => [$page->getKey()],
        $translation->getMorphClass() => [$translation->getKey()],
    ], $newerSeenAt);

    RecordCachedModelUrlsAction::run($url, [
        $page->getMorphClass() => [$page->getKey()],
    ], $olderSeenAt);

    expect(CachedModelUrl::query()->where('url', $url)->count())->toBe(2)
        ->and(CachedModelUrl::query()
            ->where('url', $url)
            ->where('cacheable_type', $translation->getMorphClass())
            ->exists())->toBeTrue();
});

it('allows multiple cached model url registration jobs for the same url to queue', function (): void {
    expect(new RegisterCachedModelUrlsJob('https://example.test/about', []))
        ->not->toBeInstanceOf(ShouldBeUnique::class);
});
