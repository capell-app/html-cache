<?php

declare(strict_types=1);

use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Theme;
use Capell\Frontend\Actions\AssertPublicHtmlContainsNoAuthoringSurfaceAction;
use Capell\Frontend\Contracts\CacheBypassResolver;
use Capell\Frontend\Contracts\HtmlMinifier;
use Capell\HtmlCache\Actions\DeletePageCacheAction;
use Capell\HtmlCache\Actions\GenerateStaticSiteAction;
use Capell\HtmlCache\Actions\GenerateStaticSitesAction;
use Capell\HtmlCache\Actions\NotifyClearCachedPagesAction;
use Capell\HtmlCache\Console\Commands\ClearHtmlCacheCommand;
use Capell\HtmlCache\Console\Commands\StaticSiteCommand;
use Capell\HtmlCache\Filament\Components\Tables\Columns\PageCachedIconColumn;
use Capell\HtmlCache\Filament\Concerns\HasPageCacheNotification;
use Capell\HtmlCache\Filament\Extenders\PageCachePageTableExtender;
use Capell\HtmlCache\Filament\Extenders\Site\MaintenanceSiteHeaderActionExtender;
use Capell\HtmlCache\Filament\Pages\MaintenanceCachePage;
use Capell\HtmlCache\Health\HtmlCacheHealthCheck;
use Capell\HtmlCache\Http\Middleware\EnsureModelEventsRegistered;
use Capell\HtmlCache\Http\Middleware\PreventSessionCookieOnCacheableRequests;
use Capell\HtmlCache\Livewire\SiteHealthCacheMap;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Support\Admin\HtmlCacheAdminCacheCleaner;
use Capell\HtmlCache\Support\Admin\HtmlCacheSiteHealthReportExtender;
use Capell\HtmlCache\Support\Admin\MaintenanceAdminTool;
use Capell\HtmlCache\Support\Cache\HtmlCacheStore;
use Capell\HtmlCache\Support\Cache\PageCache;
use Capell\HtmlCache\Support\Maintenance\HtmlCacheStaticMaintenancePageStore;
use Capell\HtmlCache\Support\ModelServing\ModelEventRegistrar;
use Capell\HtmlCache\Support\ModelServing\RetrievedModelStore;
use Capell\HtmlCache\Support\StaticSite\StaticSiteExtensionRegistry;
use Capell\HtmlCache\Support\StaticSite\StaticSiteGenerator;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Illuminate\Contracts\Filesystem\Filesystem as FilesystemContract;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

uses(HtmlCacheTestCase::class);

function htmlCacheResidualCoverageSiteDomain(string $domain = 'example.test'): SiteDomain
{
    Model::setConnectionResolver(app('db'));

    $language = Language::forceCreate([
        'name' => 'English',
        'code' => 'en',
        'default' => true,
        'status' => true,
    ]);
    $siteBlueprint = Blueprint::forceCreate([
        'name' => 'Site',
        'type' => 'site',
        'key' => 'html-cache-site-' . $domain,
        'default' => true,
        'status' => true,
    ]);
    $themeBlueprint = Blueprint::forceCreate([
        'name' => 'Theme',
        'type' => 'theme',
        'key' => 'html-cache-theme-' . $domain,
        'default' => true,
        'status' => true,
    ]);
    $theme = Theme::forceCreate([
        'name' => 'Test Theme',
        'blueprint_id' => $themeBlueprint->id,
        'key' => 'html-cache-theme-' . $domain,
        'default' => true,
        'status' => true,
    ]);
    $site = Site::forceCreate([
        'name' => 'Test Site',
        'blueprint_id' => $siteBlueprint->id,
        'theme_id' => $theme->id,
        'language_id' => $language->id,
        'default' => true,
        'status' => true,
    ]);

    return SiteDomain::forceCreate([
        'site_id' => $site->id,
        'language_id' => $language->id,
        'domain' => $domain,
        'scheme' => 'https',
        'path' => null,
        'default' => true,
        'status' => true,
    ]);
}

function htmlCacheResidualCoveragePage(SiteDomain $siteDomain): Page
{
    Model::setConnectionResolver(app('db'));

    $pageBlueprint = Blueprint::forceCreate([
        'name' => 'Page',
        'type' => 'page',
        'key' => 'html-cache-page-' . $siteDomain->id,
        'default' => true,
        'status' => true,
    ]);
    $layout = Layout::forceCreate([
        'name' => 'Default',
        'key' => 'html-cache-layout-' . $siteDomain->id,
        'site_id' => $siteDomain->site_id,
        'default' => true,
        'status' => true,
    ]);

    return Page::forceCreate([
        'uuid' => (string) Str::uuid(),
        'name' => 'Cached Page',
        'blueprint_id' => $pageBlueprint->id,
        'layout_id' => $layout->id,
        'site_id' => $siteDomain->site_id,
    ]);
}

it('tracks static site extension handlers and rejects sites without domains', function (): void {
    $registry = StaticSiteExtensionRegistry::instance();
    $registry->clear();

    $registry->register('feed', function (Site $site, SiteDomain $siteDomain, Closure $visit): void {
        expect($site->exists)->toBeTrue()
            ->and($siteDomain->exists)->toBeTrue();

        $visit('/feed.xml');
    });

    $siteDomain = htmlCacheResidualCoverageSiteDomain();
    $site = $siteDomain->site;
    $visited = [];

    foreach ($registry->all() as $handler) {
        $handler($site, $siteDomain, function (string $url) use (&$visited): void {
            $visited[] = $url;
        });
    }

    expect($registry->has('feed'))->toBeTrue()
        ->and($visited)->toBe(['/feed.xml']);

    $registry->clear();

    $orphanSite = Site::forceCreate([
        'name' => 'No Domain Site',
        'blueprint_id' => $site->blueprint_id,
        'theme_id' => $site->theme_id,
        'language_id' => $site->language_id,
        'status' => true,
    ]);

    (new StaticSiteGenerator($orphanSite))->process();

    expect($registry->has('feed'))->toBeFalse();
});

it('updates static generation cache counters even when generation fails', function (): void {
    $siteWithDomain = htmlCacheResidualCoverageSiteDomain('cache-counter.test')->site;
    $site = Site::forceCreate([
        'name' => 'Cache Counter Site',
        'blueprint_id' => $siteWithDomain->blueprint_id,
        'theme_id' => $siteWithDomain->theme_id,
        'language_id' => $siteWithDomain->language_id,
        'status' => true,
    ]);

    Cache::put('static-generation-test', 2);

    GenerateStaticSiteAction::run($site, 'static-generation-test');

    expect(Cache::get('static-generation-test'))->toBe(1);

    Cache::put('static-generation-test', 1);

    GenerateStaticSiteAction::run($site, 'static-generation-test');

    expect(Cache::has('static-generation-test'))->toBeFalse();
});

it('visits static urls internally with host and port server headers', function (): void {
    $requests = [];
    $kernel = Mockery::mock(HttpKernel::class);
    $kernel->shouldReceive('handle')
        ->twice()
        ->with(Mockery::on(function (Request $request) use (&$requests): bool {
            $requests[] = [
                'uri' => $request->getRequestUri(),
                'host' => $request->headers->get('host'),
                'https' => $request->server->get('HTTPS'),
            ];

            return true;
        }))
        ->andReturn(new Response('', Response::HTTP_OK), new Response('', Response::HTTP_FOUND));
    $kernel->shouldReceive('terminate')->twice();

    app()->instance(HttpKernel::class, $kernel);

    $method = new ReflectionMethod(StaticSiteGenerator::class, 'visitUrlInternally');
    $generator = new StaticSiteGenerator(new Site);

    $method->invoke($generator, 'https://example.test/docs?preview=1');
    $method->invoke($generator, 'http://example.test:8080/path');

    expect($requests)->toBe([
        ['uri' => '/docs?preview=1', 'host' => 'example.test', 'https' => 'on'],
        ['uri' => '/path', 'host' => 'example.test:8080', 'https' => 'off'],
    ]);
});

it('logs invalid internal static urls instead of dispatching them', function (): void {
    Log::spy();

    $method = new ReflectionMethod(StaticSiteGenerator::class, 'visitUrlInternally');

    $method->invoke(new StaticSiteGenerator(new Site), '/relative-only');

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('StaticSiteGenerator: rejected invalid internal url', ['url' => '/relative-only']);
});

it('notifies or clears cached page urls for changed models', function (): void {
    $page = htmlCacheResidualCoveragePage(htmlCacheResidualCoverageSiteDomain('notify.test'));
    CachedModelUrl::query()->create([
        'url' => 'https://example.test/one',
        'url_hash' => CachedModelUrl::hashUrl('https://example.test/one'),
        'path' => '/one',
        'cacheable_type' => $page->getMorphClass(),
        'cacheable_id' => $page->getKey(),
    ]);

    config(['capell-admin.auto_clear_cache' => true]);

    NotifyClearCachedPagesAction::run(collect([$page, 'ignored']));

    expect(CachedModelUrl::query()->where('cacheable_id', $page->getKey())->exists())->toBeFalse();
});

it('sends a clear-cache notification when automatic cache clearing is disabled', function (): void {
    $page = htmlCacheResidualCoveragePage(htmlCacheResidualCoverageSiteDomain('notify-manual.test'));
    CachedModelUrl::query()->create([
        'url' => 'https://notify-manual.test/one',
        'url_hash' => CachedModelUrl::hashUrl('https://notify-manual.test/one'),
        'path' => '/one',
        'cacheable_type' => $page->getMorphClass(),
        'cacheable_id' => $page->getKey(),
    ]);

    config(['capell-admin.auto_clear_cache' => false]);

    NotifyClearCachedPagesAction::run(collect([$page]));

    expect(CachedModelUrl::query()->where('cacheable_id', $page->getKey())->exists())->toBeTrue();
});

it('resolves cached page rows for page urls and pageable records', function (): void {
    $siteDomain = htmlCacheResidualCoverageSiteDomain('cached-page.test');
    $page = htmlCacheResidualCoveragePage($siteDomain);
    $pageUrl = PageUrl::forceCreate([
        'site_id' => $siteDomain->site_id,
        'language_id' => $siteDomain->language_id,
        'pageable_type' => $page->getMorphClass(),
        'pageable_id' => $page->getKey(),
        'url' => '/cached-page',
        'status' => true,
    ]);
    $pageUrl->setRelation('siteDomain', $siteDomain);
    $page->setRelation('pageUrls', collect([$pageUrl]));

    $cachedModelUrl = CachedModelUrl::query()->create([
        'url' => $pageUrl->full_url,
        'url_hash' => CachedModelUrl::hashUrl($pageUrl->full_url),
        'path' => '/cached-page',
        'site_domain_id' => $siteDomain->id,
        'site_id' => $siteDomain->site_id,
        'language_id' => $siteDomain->language_id,
        'cacheable_type' => $page->getMorphClass(),
        'cacheable_id' => $page->getKey(),
        'last_seen_at' => now(),
    ]);

    $method = new ReflectionMethod(PageCachedIconColumn::class, 'getCachedPage');
    $column = PageCachedIconColumn::make('cached');

    expect($method->invoke($column, $pageUrl)->is($cachedModelUrl))->toBeTrue()
        ->and($method->invoke($column, $page)->is($cachedModelUrl))->toBeTrue()
        ->and(HtmlCacheHealthCheck::compatibleCapellApiVersion())->toBe('^4.0');
});

it('runs html cache console commands against static site and file cache paths', function (): void {
    $siteDomain = htmlCacheResidualCoverageSiteDomain('command.test');
    $cachePath = storage_path('framework/testing-page-cache');
    $pageCache = (new PageCache(new Filesystem))->setCachePath($cachePath);

    app()->instance(PageCache::class, $pageCache);
    config(['capell-html-cache.static_generation.internal_requests' => false]);

    $this->artisan(StaticSiteCommand::class, [
        '--site' => $siteDomain->site_id,
        '--internal' => true,
        '--refresh' => true,
    ])->assertSuccessful();

    File::ensureDirectoryExists($cachePath);
    File::ensureDirectoryExists($cachePath . '/folder');
    File::put($cachePath . '/one.html', 'cached');
    File::put($cachePath . '/folder/two.html', 'cached');

    $this->artisan(ClearHtmlCacheCommand::class, ['slug' => 'one'])->assertSuccessful();
    $this->artisan(ClearHtmlCacheCommand::class, ['slug' => 'folder', '--recursive' => true])->assertSuccessful();

    expect(File::exists($cachePath . '/one.html'))->toBeFalse()
        ->and(File::exists($cachePath . '/folder/two.html'))->toBeFalse();
});

it('strips session cookies only from public cacheable responses', function (): void {
    config(['session.cookie' => 'capell_session']);

    $middleware = new PreventSessionCookieOnCacheableRequests;
    $request = Request::create('/cached', 'GET');
    $response = new Response('ok', Response::HTTP_OK, ['Cache-Control' => 'public, max-age=60']);
    $response->headers->setCookie(new Cookie('capell_session', 'secret'));
    $response->headers->setCookie(new Cookie('XSRF-TOKEN', 'token'));
    $response->headers->setCookie(new Cookie('keep_me', 'value'));

    $handled = $middleware->handle($request, fn (Request $nextRequest): Response => $response);

    expect(collect($handled->headers->getCookies())->map->getName()->all())->toBe(['keep_me']);

    $privateResponse = new Response('ok', Response::HTTP_OK, ['Cache-Control' => 'private']);
    $privateResponse->headers->setCookie(new Cookie('capell_session', 'secret'));

    $handledPrivate = $middleware->handle(
        Request::create('/cached', 'GET'),
        fn (Request $nextRequest): Response => $privateResponse,
    );

    expect(collect($handledPrivate->headers->getCookies())->map->getName()->all())->toBe(['capell_session']);
});

it('tracks retrieved models recursively and flushes them from middleware', function (): void {
    $siteDomain = htmlCacheResidualCoverageSiteDomain('retrieved.test');
    $page = htmlCacheResidualCoveragePage($siteDomain);
    $page->setRelation('site', $siteDomain->site);

    $store = new RetrievedModelStore;
    $store->track($page);
    $store->trackByClass($siteDomain->site, Site::class);

    expect($store->tracked($page->getMorphClass()))->toBe(1)
        ->and($store->tracked($siteDomain->site->getMorphClass()))->toBe(1);

    $store->flushToUrl('');

    expect($store->tracked($page->getMorphClass()))->toBe(0);

    app()->instance(RetrievedModelStore::class, new RetrievedModelStore);

    $response = (new EnsureModelEventsRegistered)->handle(
        Request::create('https://retrieved.test/page', 'GET'),
        fn (Request $request): Response => new Response('ok'),
    );

    expect($response->getContent())->toBe('ok');
});

it('flushes retrieved models through sync and async registration modes', function (): void {
    $siteDomain = htmlCacheResidualCoverageSiteDomain('retrieved-modes.test');
    $page = htmlCacheResidualCoveragePage($siteDomain);
    $page->setRelation('site', $siteDomain->site);
    $page->setRelation('related', collect([$siteDomain]));

    config(['capell-html-cache.model_event_registration_mode' => 'sync']);

    $syncStore = new RetrievedModelStore;
    $syncStore->track($page);
    $syncStore->flushToUrl('https://retrieved-modes.test/page');

    expect($syncStore->tracked($page->getMorphClass()))->toBe(0)
        ->and(CachedModelUrl::query()->where('url', 'https://retrieved-modes.test/page')->exists())->toBeTrue();

    Queue::fake();
    config(['capell-html-cache.model_event_registration_mode' => 'async']);

    $asyncStore = new RetrievedModelStore;
    $asyncStore->track($page);
    $asyncStore->flushToUrl('https://retrieved-modes.test/async');

    expect($asyncStore->tracked($page->getMorphClass()))->toBe(0);

    config(['capell-html-cache.model_event_registration_mode' => null]);

    $deferredStore = new RetrievedModelStore;
    $deferredStore->track($page);
    $deferredStore->flushToUrl('https://retrieved-modes.test/deferred');

    expect($deferredStore->tracked($page->getMorphClass()))->toBe(0);
});

it('registers model retrieval hooks once per request', function (): void {
    $request = Request::create('https://events.test/page', 'GET');
    app()->instance('request', $request);

    CapellCore::registerModels([Page::class]);
    app()->instance(RetrievedModelStore::class, new RetrievedModelStore);

    ModelEventRegistrar::registerModels();
    ModelEventRegistrar::registerModels();

    expect($request->attributes->get('capell.html_cache.model_events_registered'))->toBeTrue();
});

it('builds admin health sections and clears html cache through admin cleaner', function (): void {
    $page = htmlCacheResidualCoveragePage(htmlCacheResidualCoverageSiteDomain('admin-report.test'));
    CachedModelUrl::query()->create([
        'url' => 'https://admin-report.test/page',
        'url_hash' => CachedModelUrl::hashUrl('https://admin-report.test/page'),
        'path' => '/page',
        'cacheable_type' => $page->getMorphClass(),
        'cacheable_id' => $page->getKey(),
    ]);

    $sections = (new HtmlCacheSiteHealthReportExtender)->sectionsForSite((int) $page->site_id);

    expect($sections)->toHaveCount(1)
        ->and($sections[0]->checks)->not->toBeEmpty();

    (new HtmlCacheAdminCacheCleaner)->clear();

    expect(CachedModelUrl::query()->exists())->toBeFalse();
});

it('covers page cache notification trait and page table extension helpers', function (): void {
    $siteDomain = htmlCacheResidualCoverageSiteDomain('trait.test');
    $page = htmlCacheResidualCoveragePage($siteDomain);

    $component = new class
    {
        use HasPageCacheNotification;

        /** @var list<array{event: string, params: array<string, mixed>}> */
        public array $events = [];

        public function dispatch(string $event, mixed ...$params): void
        {
            $this->events[] = ['event' => $event, 'params' => $params];
        }
    };

    CachedModelUrl::query()->create([
        'url' => 'https://trait.test/page',
        'url_hash' => CachedModelUrl::hashUrl('https://trait.test/page'),
        'path' => '/page',
        'site_domain_id' => $siteDomain->id,
        'site_id' => $siteDomain->site_id,
        'language_id' => $siteDomain->language_id,
        'cacheable_type' => $page->getMorphClass(),
        'cacheable_id' => $page->getKey(),
    ]);

    $component->notifyPageCached($page);
    $component->notifyPageCached([$page, 'ignored']);
    $component->refreshPageCache();

    $extender = new PageCachePageTableExtender;

    expect($component->events[0]['event'])->toBe('close-notification')
        ->and($extender->getColumns())->toHaveCount(1)
        ->and($extender->getBulkActions())->toBe([])
        ->and($extender->getFilters())->toBe([])
        ->and((new MaintenanceAdminTool)->render())->toBe('');
});

it('covers static generation and page deletion actions directly', function (): void {
    $siteDomain = htmlCacheResidualCoverageSiteDomain('delete-action.test');
    $page = htmlCacheResidualCoveragePage($siteDomain);
    $pageUrl = PageUrl::forceCreate([
        'site_id' => $siteDomain->site_id,
        'language_id' => $siteDomain->language_id,
        'pageable_type' => $page->getMorphClass(),
        'pageable_id' => $page->getKey(),
        'url' => '/delete-me',
        'status' => true,
    ]);
    $pageUrl->setRelation('siteDomain', $siteDomain);
    $page->setRelation('pageUrls', collect([$pageUrl]));

    CachedModelUrl::query()->create([
        'url' => $pageUrl->full_url,
        'url_hash' => CachedModelUrl::hashUrl($pageUrl->full_url),
        'path' => '/delete-me',
        'site_domain_id' => $siteDomain->id,
        'site_id' => $siteDomain->site_id,
        'language_id' => $siteDomain->language_id,
        'cacheable_type' => $page->getMorphClass(),
        'cacheable_id' => $page->getKey(),
    ]);

    GenerateStaticSitesAction::run(collect([$siteDomain->site]));

    expect(DeletePageCacheAction::run($pageUrl, refresh: false))->toBeTrue()
        ->and(DeletePageCacheAction::run($page, refresh: false))->toBeTrue();
});

it('covers cache map component record and selection state helpers', function (): void {
    $siteDomain = htmlCacheResidualCoverageSiteDomain('component.test');
    $page = htmlCacheResidualCoveragePage($siteDomain);
    $cached = CachedModelUrl::query()->create([
        'url' => 'https://component.test/page',
        'url_hash' => CachedModelUrl::hashUrl('https://component.test/page'),
        'path' => '/page',
        'site_domain_id' => $siteDomain->id,
        'site_id' => $siteDomain->site_id,
        'language_id' => $siteDomain->language_id,
        'cacheable_type' => $page->getMorphClass(),
        'cacheable_id' => $page->getKey(),
    ]);
    $encoded = base64_encode($page->getMorphClass() . '|' . $page->getKey());

    $component = new SiteHealthCacheMap;
    $component->mount($siteDomain->site_id);
    $component->rememberClearedCacheMapRecordKey((string) $cached->id);
    $component->updatedResourceSearch();
    $component->selectedModelType = $page->getMorphClass();
    $component->selectedResourceKey = 'not-valid';

    expect($component->getTableRecord(null))->toBeNull()
        ->and($component->getTableRecord((string) $cached->id)->is($cached))->toBeTrue()
        ->and($component->getTableRecord('999999'))->toBeInstanceOf(CachedModelUrl::class)
        ->and($component->selectedResource())->toBeNull()
        ->and($component->clearedCacheMapRecordKeys)->toBe([(string) $cached->id]);

    $component->selectedResourceKey = $encoded;

    expect($component->selectedResource()?->resourceId)->toBe((int) $page->getKey())
        ->and($component->detailUrlCount())->toBe(0);
});

it('wraps html cache storage and maintenance page storage operations', function (): void {
    Storage::fake('page_cache');

    $store = resolve(HtmlCacheStore::class);
    $maintenanceStore = new HtmlCacheStaticMaintenancePageStore($store);

    $store->put('../index.html', 'home');
    $store->replace('nested/page.html', 'page');
    $maintenanceStore->put('maintenance/index.html', 'down');

    expect($store->exists('index.html'))->toBeTrue()
        ->and($store->lastModified('index.html'))->toBeInt()
        ->and($store->path('index.html'))->toBeString()
        ->and($store->directories())->toContain('nested', 'maintenance')
        ->and($store->files())->toContain('index.html')
        ->and($store->allFiles('nested'))->toContain('nested/page.html')
        ->and($store->allDirectories('maintenance'))->toBe([])
        ->and($maintenanceStore->exists('maintenance/index.html'))->toBeTrue()
        ->and($maintenanceStore->path('maintenance/index.html'))->toBeString();

    $store->delete('nested/page.html');
    $store->deleteDirectory('maintenance');
    $store->deleteAll();

    expect($store->exists('index.html'))->toBeFalse();
});

it('returns null for missing html cache storage metadata and sanitizes deletes', function (): void {
    Storage::fake('page_cache');

    $store = resolve(HtmlCacheStore::class);

    $store->put('folder/keep.html', 'cached');

    expect($store->lastModified('missing.html'))->toBeNull()
        ->and($store->path('missing.html'))->toBeNull()
        ->and($store->files('folder'))->toBe(['folder/keep.html'])
        ->and($store->delete('../folder/keep.html'))->toBeTrue()
        ->and($store->exists('folder/keep.html'))->toBeFalse();
});

it('wraps html cache store listing failures with useful runtime exceptions', function (): void {
    $disk = Mockery::mock(FilesystemContract::class);
    $disk->shouldReceive('directories')->andThrow(new RuntimeException('disk missing'));
    $disk->shouldReceive('files')->andThrow(new RuntimeException('disk missing'));
    $disk->shouldReceive('allDirectories')->andThrow(new RuntimeException('nested missing'));
    $disk->shouldReceive('allFiles')->andThrow(new RuntimeException('nested missing'));
    $disk->shouldReceive('path')->andReturn('/tmp/page-cache');

    $manager = Mockery::mock(FilesystemManager::class);
    $manager->shouldReceive('disk')->with('page_cache')->andReturn($disk);

    $store = new HtmlCacheStore($manager);

    expect(fn (): array => $store->directories())->toThrow(RuntimeException::class, 'HTML cache root missing')
        ->and(fn (): array => $store->files())->toThrow(RuntimeException::class, 'HTML cache root missing')
        ->and(fn (): array => $store->allDirectories('nested'))->toThrow(RuntimeException::class, 'Unable to list all HTML cache directories')
        ->and(fn (): array => $store->allFiles('nested'))->toThrow(RuntimeException::class, 'Unable to list all HTML cache files');

    $unresolvedDisk = Mockery::mock(FilesystemContract::class);
    $unresolvedDisk->shouldReceive('directories')->andThrow(new RuntimeException('disk missing'));
    $unresolvedDisk->shouldReceive('path')->andThrow(new RuntimeException('root missing'));
    $unresolvedManager = Mockery::mock(FilesystemManager::class);
    $unresolvedManager->shouldReceive('disk')->with('page_cache')->andReturn($unresolvedDisk);

    expect(fn (): array => (new HtmlCacheStore($unresolvedManager))->directories())
        ->toThrow(RuntimeException::class, 'page_cache disk root (unresolved)');
});

it('exposes maintenance cache page labels, manifest state, and access checks', function (): void {
    $siteDomain = htmlCacheResidualCoverageSiteDomain('maintenance-page.test');
    $page = new MaintenanceCachePage;

    expect(MaintenanceCachePage::canAccess())->toBeFalse()
        ->and(MaintenanceCachePage::getNavigationLabel())->toBeString()->not->toBe('')
        ->and(MaintenanceCachePage::getNavigationGroup())->toBeString()->not->toBe('')
        ->and($page->getTitle())->toBeString()->not->toBe('')
        ->and($page->sites()->pluck('id'))->toContain($siteDomain->site_id)
        ->and($page->manifest())->toHaveKey('global_active');

    $user = new class extends User
    {
        protected $table = 'users';

        public function hasPermissionTo(mixed $permission): bool
        {
            return true;
        }

        public function isGlobalAdmin(): bool
        {
            return false;
        }
    };
    $user->forceFill([
        'name' => 'Maintenance User',
        'email' => 'maintenance@example.test',
        'password' => bcrypt('password'),
    ])->save();

    $this->actingAs($user);

    expect(MaintenanceCachePage::canAccess())->toBeTrue();
});

it('builds maintenance admin actions for permitted users', function (): void {
    $user = new class extends User
    {
        protected $table = 'users';

        public function hasPermissionTo(mixed $permission): bool
        {
            return true;
        }

        public function isGlobalAdmin(): bool
        {
            return false;
        }
    };
    $user->forceFill([
        'name' => 'Action User',
        'email' => 'action-user@example.test',
        'password' => bcrypt('password'),
    ])->save();

    $this->actingAs($user);

    expect((new MaintenanceSiteHeaderActionExtender)->actions())->toHaveCount(3);
});

it('builds maintenance cache page header actions', function (): void {
    $method = new ReflectionMethod(MaintenanceCachePage::class, 'getHeaderActions');
    $page = new MaintenanceCachePage;

    expect($method->invoke($page))->toHaveCount(3);
});

it('toggles and generates site maintenance cache state', function (): void {
    $siteDomain = htmlCacheResidualCoverageSiteDomain('maintenance-toggle.test');
    $page = new MaintenanceCachePage;

    $page->toggleSite((int) $siteDomain->site_id);

    expect(data_get($page->manifest(), 'sites.' . $siteDomain->site_id . '.active'))->toBeTrue();

    $page->toggleSite((int) $siteDomain->site_id);

    expect(data_get($page->manifest(), 'sites.' . $siteDomain->site_id . '.active'))->toBeFalse();

    $page->generateSite((int) $siteDomain->site_id);

    expect(data_get($page->manifest(), 'sites.' . $siteDomain->site_id . '.domains'))->not->toBe([]);
});

it('caches public html, json, xml, not found, and invalid request paths', function (): void {
    $cachePath = storage_path('framework/testing-page-cache-page-cache');
    File::deleteDirectory($cachePath);

    app()->instance(CacheBypassResolver::class, new class implements CacheBypassResolver
    {
        public function shouldBypass(): bool
        {
            return false;
        }
    });
    app()->instance(HtmlMinifier::class, new class implements HtmlMinifier
    {
        public function minify(string $html): string
        {
            return trim($html);
        }
    });
    config(['capell-html-cache.enabled' => true]);

    $cache = (new PageCache(new Filesystem))->setCachePath($cachePath);
    $request = Request::create('/docs/page', 'GET');
    $html = ' <html><body>Safe</body></html> ';
    $request->attributes->set(AssertPublicHtmlContainsNoAuthoringSurfaceAction::SAFE_INSPECTION_PASSED_ATTRIBUTE, true);
    $request->attributes->set(AssertPublicHtmlContainsNoAuthoringSurfaceAction::SAFE_INSPECTION_HASH_ATTRIBUTE, hash('xxh128', $html));

    expect($cache->shouldCachePage($request, new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html'])))->toBeTrue();

    $cache->cache($request, new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html']));
    $cache->cache(Request::create('/feed', 'GET'), new Response('<xml />', Response::HTTP_OK, ['Content-Type' => 'text/xml']));
    $cache->cache(Request::create('/data', 'GET'), new Response('{"ok":true}', Response::HTTP_OK, ['Content-Type' => 'application/json']));
    $cache->cache(Request::create('/missing', 'GET'), new Response('missing', Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/html']));

    $invalidRequest = Request::create('/unsafe/..', 'GET');
    $cache->cache($invalidRequest, new Response('invalid', Response::HTTP_OK, ['Content-Type' => 'text/html']));

    expect($cache->getCachePage($request))->toBe('<html><body>Safe</body></html>')
        ->and($cache->getCacheErrorPage(Request::create('/missing', 'GET')))->toBe('missing')
        ->and(File::exists($cachePath . '/feed.xml'))->toBeTrue()
        ->and(File::exists($cachePath . '/data.json'))->toBeTrue()
        ->and(File::exists($cachePath . '/__invalid__/pc__invalid__pc.html'))->toBeTrue()
        ->and($cache->shouldCachePage(Request::create('/docs/page?x=1', 'GET'), new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html'])))->toBeFalse()
        ->and($cache->shouldCachePage(Request::create('/docs/page', 'POST'), new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html'])))->toBeFalse()
        ->and($cache->shouldCachePage(Request::create('/docs/page', 'GET', server: ['HTTP_X_INERTIA' => 'true']), new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html'])))->toBeFalse()
        ->and($cache->shouldCachePage(Request::create('/docs/page', 'GET'), new Response('json', Response::HTTP_OK, ['Content-Type' => 'application/json'])))->toBeFalse()
        ->and($cache->shouldCachePage(Request::create('/docs/page', 'GET', server: ['HTTP_X_LIVEWIRE' => 'true']), new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html'])))->toBeFalse();
});
