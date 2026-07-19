<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Providers;

use Capell\Admin\Contracts\AdminTools\AdminToolItem;
use Capell\Admin\Contracts\Cache\AdminCacheCleaner;
use Capell\Admin\Contracts\Cache\StaticSiteGenerationDispatcher;
use Capell\Admin\Contracts\DashboardSettingsContributor;
use Capell\Admin\Contracts\Diagnostics\SiteHealthReportExtender;
use Capell\Admin\Contracts\Diagnostics\SiteHealthWidget;
use Capell\Admin\Contracts\Extenders\PageTableExtender;
use Capell\Admin\Contracts\Extenders\SiteHeaderActionExtender;
use Capell\Admin\Enums\DashboardEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Core\Events\FrontendSurrogateKeysInvalidated;
use Capell\Core\Exceptions\UrlMissingSiteDomainException;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\Core\Support\Packages\AbstractPackageServiceProvider;
use Capell\Frontend\Contracts\FrontendOutputCacheInvalidator;
use Capell\Frontend\Contracts\RenderedModelTracker;
use Capell\Frontend\Contracts\StaticErrorPageStore;
use Capell\Frontend\Contracts\StaticMaintenancePageStore;
use Capell\Frontend\Support\Routing\FrontendRouteMiddlewareRegistry;
use Capell\HtmlCache\Actions\ClearAllHtmlCacheAction;
use Capell\HtmlCache\Actions\ClearCachedUrlAction;
use Capell\HtmlCache\Actions\ClearCachedUrlsForSurrogateKeysAction;
use Capell\HtmlCache\Actions\EnsureHtmlCachePermissionsAction;
use Capell\HtmlCache\Actions\MarkAllCachedUrlsStaleAction;
use Capell\HtmlCache\Actions\MarkCachedUrlStaleAction;
use Capell\HtmlCache\Bridges\HtmlCacheAdminBridge;
use Capell\HtmlCache\Console\Commands\ClearHtmlCacheCommand;
use Capell\HtmlCache\Console\Commands\DiagnoseHtmlCacheCommand;
use Capell\HtmlCache\Console\Commands\ProcessStaleHtmlCacheCommand;
use Capell\HtmlCache\Console\Commands\StaticSiteCommand;
use Capell\HtmlCache\Contracts\CachePurger;
use Capell\HtmlCache\Filament\Extenders\PageCachePageTableExtender;
use Capell\HtmlCache\Filament\Extenders\Site\MaintenanceSiteHeaderActionExtender;
use Capell\HtmlCache\Filament\Pages\MaintenanceCachePage;
use Capell\HtmlCache\Filament\Settings\Contributors\HtmlCacheDashboardSettingsContributor;
use Capell\HtmlCache\Filament\Widgets\CacheCoverageUrlsFilamentWidget;
use Capell\HtmlCache\Filament\Widgets\HtmlCacheOverviewFilamentWidget;
use Capell\HtmlCache\Filament\Widgets\HtmlCacheStaleQueueFilamentWidget;
use Capell\HtmlCache\Http\Middleware\EnsureModelEventsRegistered;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Http\Middleware\PreventSessionCookieOnCacheableRequests;
use Capell\HtmlCache\Livewire\SiteHealthCacheMap;
use Capell\HtmlCache\Observers\HtmlCacheModelInvalidationObserver;
use Capell\HtmlCache\Support\AccessGate\ActiveAccessGateAreaResolver;
use Capell\HtmlCache\Support\Admin\HtmlCacheAdminCacheCleaner;
use Capell\HtmlCache\Support\Admin\HtmlCacheSiteHealthReportExtender;
use Capell\HtmlCache\Support\Admin\HtmlCacheSiteHealthWidget;
use Capell\HtmlCache\Support\Admin\HtmlCacheStaticSiteGenerationDispatcher;
use Capell\HtmlCache\Support\Admin\MaintenanceAdminTool;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Capell\HtmlCache\Support\Cache\HtmlCacheStore;
use Capell\HtmlCache\Support\Cache\HtmlFrontendOutputCacheInvalidator;
use Capell\HtmlCache\Support\Cache\PageCache;
use Capell\HtmlCache\Support\Cache\Purgers\CloudflareCachePurger;
use Capell\HtmlCache\Support\Cache\Purgers\HttpSurrogateKeyCachePurger;
use Capell\HtmlCache\Support\Cache\Purgers\NullCachePurger;
use Capell\HtmlCache\Support\Error\HtmlCacheStaticErrorPageStore;
use Capell\HtmlCache\Support\Extensions\ExtensionCacheSafetyResolver;
use Capell\HtmlCache\Support\Maintenance\HtmlCacheStaticMaintenancePageStore;
use Capell\HtmlCache\Support\ModelServing\RetrievedModelStore;
use Capell\HtmlCache\Support\SiteDiscovery\HtmlCacheGeneratedOutputCoverageSource;
use Capell\HtmlCache\Support\StaticSite\StaticSiteExtensionRegistry;
use Capell\HtmlCache\Support\Telemetry\HtmlCacheHitBuffer;
use Capell\SiteDiscovery\Contracts\GeneratedOutputCoverageSource;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Override;
use RuntimeException;
use Spatie\LaravelPackageTools\Package;

final class HtmlCacheServiceProvider extends AbstractPackageServiceProvider
{
    public static string $name = 'capell-html-cache';

    public static string $packageName = 'capell-app/html-cache';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(self::$name)
            ->hasConfigFile('capell-html-cache')
            ->hasTranslations()
            ->hasViews('capell-html-cache')
            ->hasMigration('2026_05_10_190854_01_create_cached_model_urls_table')
            ->hasMigration('2026_05_14_000001_create_stale_cached_urls_table')
            ->hasMigration('2026_06_07_000001_add_telemetry_to_cached_model_urls_table')
            ->hasMigration('2026_07_18_000001_create_html_cache_generation_runs_table');
    }

    public function registeringPackage(): void
    {
        parent::registeringPackage();

        $this->registerPageCacheDisk();

        $this->app->singleton(HtmlCachePathResolver::class);
        $this->app->singleton(StaticSiteGenerationDispatcher::class, HtmlCacheStaticSiteGenerationDispatcher::class);
        $this->app->singleton(HtmlCacheStore::class);
        $this->app->singleton(FrontendOutputCacheInvalidator::class, HtmlFrontendOutputCacheInvalidator::class);
        $this->app->singleton(HtmlCacheHitBuffer::class);
        $this->app->singleton(CachePurger::class, function (): CachePurger {
            return match (config('capell-html-cache.purge.driver')) {
                'cloudflare' => $this->app->make(CloudflareCachePurger::class),
                'http' => $this->app->make(HttpSurrogateKeyCachePurger::class),
                default => $this->app->make(NullCachePurger::class),
            };
        });
        $this->app->singleton(ActiveAccessGateAreaResolver::class);
        $this->app->singleton(ExtensionCacheSafetyResolver::class);
        $this->app->singleton(StaticSiteExtensionRegistry::class, fn (): StaticSiteExtensionRegistry => StaticSiteExtensionRegistry::instance());
        $this->app->scoped(RetrievedModelStore::class, fn (): RetrievedModelStore => new RetrievedModelStore);
        $this->app->scoped(RenderedModelTracker::class, fn (): RenderedModelTracker => $this->app->make(RetrievedModelStore::class));

        if (! $this->app->bound(FrontendRouteMiddlewareRegistry::class)) {
            $this->app->singleton(FrontendRouteMiddlewareRegistry::class);
        }

        $app = $this->app;

        $this->app->bind(PageCache::class, function () use ($app): PageCache {
            $instance = new PageCache(resolve(Filesystem::class));
            $store = resolve(HtmlCacheStore::class);

            $request = request();
            $domainPath = $request->getScheme() . '.' . $request->getHost();
            $cachePath = $store->path($domainPath)
                ?? rtrim($store->root(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $domainPath;

            if (config('capell-html-cache.enabled', false) && ! is_dir($cachePath) && (! @mkdir($cachePath, 0755, true) && ! is_dir($cachePath))) {
                throw new RuntimeException(sprintf('Unable to create HTML cache directory [%s].', $cachePath));
            }

            $instance->setCachePath($cachePath);
            $instance->setContainer($app);

            return $instance;
        });

        $this
            ->registerMiddlewareAliases()
            ->registerFrontendMiddleware();
    }

    public function packageBooted(): void
    {
        $this
            ->registerCommands()
            ->registerOptimization();

        if (! $this->isPackageInstalled()) {
            return;
        }

        $this
            ->registerPageCacheDisk()
            ->registerMaintenanceStorage()
            ->registerErrorPageStorage()
            ->registerAdminBridge()
            ->registerAdminExtenders()
            ->registerDashboardSettingsContributor()
            ->registerDashboardFilamentWidgets()
            ->registerGeneratedOutputCoverage()
            ->registerModelInvalidationHooks()
            ->registerScheduledInvalidation()
            ->ensurePermissions();
    }

    #[Override]
    protected function isPackageInstalled(): bool
    {
        return CapellCore::isPackageInstalled(self::$packageName);
    }

    private function registerMiddlewareAliases(): self
    {
        Route::aliasMiddleware('frontend.cache', HtmlCacheMiddleware::class);
        Route::aliasMiddleware('frontend.model_events', EnsureModelEventsRegistered::class);
        Route::aliasMiddleware('frontend.no_session_cookies_on_cache', PreventSessionCookieOnCacheableRequests::class);

        return $this;
    }

    private function registerMaintenanceStorage(): self
    {
        $this->app->singleton(HtmlCacheStaticMaintenancePageStore::class);
        $this->app->singleton(
            StaticMaintenancePageStore::class,
            fn (): HtmlCacheStaticMaintenancePageStore => $this->app->make(HtmlCacheStaticMaintenancePageStore::class),
        );

        return $this;
    }

    private function registerErrorPageStorage(): self
    {
        $this->app->singleton(HtmlCacheStaticErrorPageStore::class);
        $this->app->singleton(
            StaticErrorPageStore::class,
            fn (): HtmlCacheStaticErrorPageStore => $this->app->make(HtmlCacheStaticErrorPageStore::class),
        );

        return $this;
    }

    private function registerFrontendMiddleware(): self
    {
        $this->app->afterResolving(
            FrontendRouteMiddlewareRegistry::class,
            fn (FrontendRouteMiddlewareRegistry $registry): FrontendRouteMiddlewareRegistry => $this->configureFrontendMiddleware($registry),
        );

        if ($this->app->resolved(FrontendRouteMiddlewareRegistry::class)) {
            $this->configureFrontendMiddleware(resolve(FrontendRouteMiddlewareRegistry::class));
        }

        return $this;
    }

    private function configureFrontendMiddleware(FrontendRouteMiddlewareRegistry $registry): FrontendRouteMiddlewareRegistry
    {
        return $registry
            ->insertBefore('web', [
                'frontend.no_session_cookies_on_cache',
            ])
            ->insertAfter('web', [
                'frontend.cache',
            ])
            ->insertAfter('frontend.anonymous_cacheable_render', [
                'frontend.model_events',
            ]);
    }

    private function registerPageCacheDisk(): self
    {
        $pageCacheDisk = config('filesystems.disks.page_cache');

        if (! is_array($pageCacheDisk) || ! isset($pageCacheDisk['driver'])) {
            config(['filesystems.disks.page_cache' => [
                'driver' => 'local',
                'root' => public_path('page-cache'),
                'throw' => false,
            ]]);
        }

        return $this;
    }

    private function registerAdminBridge(): self
    {
        CapellAdmin::registerAdminBridge(self::$packageName, HtmlCacheAdminBridge::class);
        CapellAdmin::registerExtensionPage(self::$packageName, MaintenanceCachePage::class);
        CapellAdmin::bootAdminBridges(self::$packageName);

        return $this;
    }

    private function registerAdminExtenders(): self
    {
        $this->app->bind(PageCachePageTableExtender::class);
        $this->app->bind(HtmlCacheAdminCacheCleaner::class);
        $this->app->bind(HtmlCacheSiteHealthReportExtender::class);
        $this->app->bind(HtmlCacheSiteHealthWidget::class);
        $this->app->bind(MaintenanceAdminTool::class);
        $this->app->bind(MaintenanceSiteHeaderActionExtender::class);

        $this->app->tag(PageCachePageTableExtender::class, PageTableExtender::TAG);
        $this->app->tag(HtmlCacheAdminCacheCleaner::class, AdminCacheCleaner::TAG);
        $this->app->tag(HtmlCacheSiteHealthReportExtender::class, SiteHealthReportExtender::TAG);
        $this->app->tag(HtmlCacheSiteHealthWidget::class, SiteHealthWidget::TAG);
        $this->app->tag(MaintenanceAdminTool::class, AdminToolItem::TAG);
        $this->app->tag(MaintenanceSiteHeaderActionExtender::class, SiteHeaderActionExtender::TAG);
        Livewire::component('capell-html-cache.site-health-cache-map', SiteHealthCacheMap::class);

        return $this;
    }

    private function registerDashboardSettingsContributor(): self
    {
        $this->app->tag([HtmlCacheDashboardSettingsContributor::class], DashboardSettingsContributor::TAG);

        return $this;
    }

    private function registerDashboardFilamentWidgets(): self
    {
        CapellAdmin::registerDashboardFilamentWidget(HtmlCacheOverviewFilamentWidget::class, DashboardEnum::Main);
        CapellAdmin::registerDashboardFilamentWidget(CacheCoverageUrlsFilamentWidget::class, DashboardEnum::Main);
        CapellAdmin::registerDashboardFilamentWidget(HtmlCacheStaleQueueFilamentWidget::class, DashboardEnum::Main);

        return $this;
    }

    private function registerGeneratedOutputCoverage(): self
    {
        if (! interface_exists(GeneratedOutputCoverageSource::class)) {
            return $this;
        }

        $this->app->singleton(HtmlCacheGeneratedOutputCoverageSource::class);
        $this->app->tag([HtmlCacheGeneratedOutputCoverageSource::class], GeneratedOutputCoverageSource::TAG);

        return $this;
    }

    private function registerModelInvalidationHooks(): self
    {
        $broadRouteModelClasses = [Page::class];

        foreach ($broadRouteModelClasses as $modelClass) {
            $modelClass::created(function (Model $model): mixed {
                $this->dispatchClearAllHtmlCache();

                return null;
            });
            $modelClass::deleted(function (Model $model): mixed {
                $this->dispatchClearAllHtmlCache();

                return null;
            });
        }

        PageUrl::created(function (PageUrl $pageUrl): mixed {
            $this->dispatchClearPageUrlCache($pageUrl);

            return null;
        });
        PageUrl::updated(function (PageUrl $pageUrl): mixed {
            if ($this->isTimestampOnlyUpdate($pageUrl)) {
                return null;
            }

            $this->dispatchClearPageUrlCache($pageUrl);

            return null;
        });
        PageUrl::deleted(function (PageUrl $pageUrl): mixed {
            $this->dispatchClearPageUrlCache($pageUrl);

            return null;
        });

        SiteDomain::saved(function (SiteDomain $siteDomain): mixed {
            if (! $siteDomain->wasRecentlyCreated && ! $siteDomain->wasChanged(['scheme', 'domain', 'path', 'site_id', 'language_id'])) {
                return null;
            }

            if ($siteDomain->wasChanged(['scheme', 'domain', 'path', 'site_id', 'language_id'])) {
                $this->dispatchClearAllHtmlCacheImmediately();

                return null;
            }

            $this->dispatchClearAllHtmlCache($this->originalSiteDomainAttributes($siteDomain));

            return null;
        });
        SiteDomain::deleted(function (SiteDomain $siteDomain): mixed {
            $this->dispatchClearAllHtmlCacheImmediately();

            return null;
        });

        Event::listen('eloquent.created: *', [HtmlCacheModelInvalidationObserver::class, 'createdFromEvent']);
        Event::listen('eloquent.updated: *', [HtmlCacheModelInvalidationObserver::class, 'updatedFromEvent']);
        Event::listen('eloquent.deleted: *', [HtmlCacheModelInvalidationObserver::class, 'deletedFromEvent']);
        Event::listen(FrontendSurrogateKeysInvalidated::class, function (FrontendSurrogateKeysInvalidated $event): void {
            $this->dispatchClearSurrogateKeyCache($event->surrogateKeys);
        });

        return $this;
    }

    private function isTimestampOnlyUpdate(Model $model): bool
    {
        $changedAttributes = array_keys($model->getChanges());

        return $changedAttributes !== []
            && array_diff($changedAttributes, [$model->getUpdatedAtColumn()]) === [];
    }

    /**
     * @param  array<string, mixed>|null  $cachePathSiteDomainAttributes
     */
    private function dispatchClearAllHtmlCache(?array $cachePathSiteDomainAttributes = null): void
    {
        if ($this->usesScheduledInvalidation()) {
            MarkAllCachedUrlsStaleAction::dispatch('all_changed', $cachePathSiteDomainAttributes)->afterCommit();

            return;
        }

        ClearAllHtmlCacheAction::dispatch()->afterCommit();
    }

    private function dispatchClearAllHtmlCacheImmediately(): void
    {
        ClearAllHtmlCacheAction::dispatch()->afterCommit();
    }

    private function dispatchClearPageUrlCache(PageUrl $pageUrl): void
    {
        $url = $this->pageUrlFullUrl($pageUrl);

        if ($url === null) {
            return;
        }

        if ($this->usesScheduledInvalidation()) {
            MarkCachedUrlStaleAction::dispatch($url, 'page_url_changed')->afterCommit();

            return;
        }

        ClearCachedUrlAction::dispatch($url)->afterCommit();
    }

    /**
     * @param  array<int, string>  $surrogateKeys
     */
    private function dispatchClearSurrogateKeyCache(array $surrogateKeys): void
    {
        if ($surrogateKeys === []) {
            return;
        }

        ClearCachedUrlsForSurrogateKeysAction::dispatch($surrogateKeys)->afterCommit();
    }

    private function pageUrlFullUrl(PageUrl $pageUrl): ?string
    {
        try {
            return $pageUrl->fullUrl();
        } catch (UrlMissingSiteDomainException) {
            return null;
        }
    }

    private function registerCommands(): self
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ClearHtmlCacheCommand::class,
                DiagnoseHtmlCacheCommand::class,
                ProcessStaleHtmlCacheCommand::class,
                StaticSiteCommand::class,
            ]);
        }

        return $this;
    }

    private function ensurePermissions(): self
    {
        $table = config('permission.table_names.permissions', 'permissions');

        if (is_string($table) && resolve(RuntimeSchemaState::class)->hasTable($table)) {
            EnsureHtmlCachePermissionsAction::run();
        }

        return $this;
    }

    private function registerScheduledInvalidation(): self
    {
        if (! $this->usesScheduledInvalidation()) {
            return $this;
        }

        $frequency = config('capell-html-cache.invalidation.schedule', 'everyFiveMinutes');

        if (! is_string($frequency) || $frequency === '') {
            $frequency = 'everyFiveMinutes';
        }

        $overlapExpiresAfterMinutes = $this->configuredSchedulerOverlapMinutes();

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) use ($frequency, $overlapExpiresAfterMinutes): void {
            $event = $schedule
                ->command('capell:html-cache:process-stale')
                ->withoutOverlapping($overlapExpiresAfterMinutes)
                ->onOneServer();

            match ($frequency) {
                'everyMinute' => $event->everyMinute(),
                'everyTwoMinutes' => $event->everyTwoMinutes(),
                'everyThreeMinutes' => $event->everyThreeMinutes(),
                'everyFourMinutes' => $event->everyFourMinutes(),
                'everyTenMinutes' => $event->everyTenMinutes(),
                'everyFifteenMinutes' => $event->everyFifteenMinutes(),
                'everyThirtyMinutes' => $event->everyThirtyMinutes(),
                'hourly' => $event->hourly(),
                default => $event->everyFiveMinutes(),
            };
        });

        return $this;
    }

    private function configuredSchedulerOverlapMinutes(): int
    {
        $configuredTimeout = config('capell-html-cache.invalidation.processing_timeout_minutes', 15);
        $processingTimeout = is_int($configuredTimeout) ? $configuredTimeout : 15;

        return max(10, $processingTimeout + 5);
    }

    private function usesScheduledInvalidation(): bool
    {
        return config('capell-html-cache.invalidation.mode', 'instant') === 'scheduled';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function originalSiteDomainAttributes(SiteDomain $siteDomain): ?array
    {
        if (! $siteDomain->wasChanged(['scheme', 'domain', 'path'])) {
            return null;
        }

        return [
            'id' => $siteDomain->getKey(),
            'site_id' => $siteDomain->getOriginal('site_id'),
            'language_id' => $siteDomain->getOriginal('language_id'),
            'scheme' => $siteDomain->getOriginal('scheme'),
            'domain' => $siteDomain->getOriginal('domain'),
            'path' => $siteDomain->getOriginal('path'),
            'status' => $siteDomain->getOriginal('status'),
        ];
    }

    private function registerOptimization(): self
    {
        $this->optimizes(clear: ClearHtmlCacheCommand::class, key: 'html-page-cache');

        return $this;
    }
}
