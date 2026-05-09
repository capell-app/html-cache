<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Providers;

use Capell\Admin\Contracts\Extenders\PageTableExtender;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Packages\AbstractPackageServiceProvider;
use Capell\Frontend\Contracts\RenderedModelTracker;
use Capell\HtmlCache\Actions\ClearAllHtmlCacheAction;
use Capell\HtmlCache\Actions\ClearCachedUrlsForModelAction;
use Capell\HtmlCache\Bridges\HtmlCacheAdminBridge;
use Capell\HtmlCache\Console\Commands\StaticSiteCommand;
use Capell\HtmlCache\Filament\Extenders\PageCachePageTableExtender;
use Capell\HtmlCache\Http\Middleware\EnsureModelEventsRegistered;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Http\Middleware\PreventSessionCookieOnCacheableRequests;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Capell\HtmlCache\Support\Cache\HtmlCacheStore;
use Capell\HtmlCache\Support\Cache\PageCache;
use Capell\HtmlCache\Support\ModelServing\RetrievedModelStore;
use Capell\HtmlCache\Support\StaticSite\StaticSiteExtensionRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Route;
use Silber\PageCache\Console\ClearCache;
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
            ->hasMigration('2026_05_09_000001_create_cached_model_urls_table');
    }

    public function registeringPackage(): void
    {
        parent::registeringPackage();

        $this->app->singleton(HtmlCachePathResolver::class);
        $this->app->singleton(HtmlCacheStore::class);
        $this->app->singleton(StaticSiteExtensionRegistry::class, fn (): StaticSiteExtensionRegistry => StaticSiteExtensionRegistry::instance());
        $this->app->scoped(RetrievedModelStore::class, fn (): RetrievedModelStore => new RetrievedModelStore);
        $this->app->scoped(RenderedModelTracker::class, fn (): RenderedModelTracker => $this->app->make(RetrievedModelStore::class));

        $this->app->bind(PageCache::class, function (): PageCache {
            $instance = new PageCache(resolve(Filesystem::class));
            $store = resolve(HtmlCacheStore::class);

            $request = request();
            $domainPath = $request->getScheme() . '.' . $request->getHost();
            $cachePath = $store->path($domainPath) ?? ($store->root() . $domainPath);

            if (config('capell-html-cache.enabled', false) && ! is_dir($cachePath)) {
                mkdir($cachePath, 0755, true);
            }

            return $instance->setCachePath($cachePath)->setContainer($this->app);
        });

        CapellCore::registerPackage(
            self::$packageName,
            type: self::getType(),
            serviceProviderClass: self::class,
            path: realpath(__DIR__ . '/../..'),
            version: CapellCore::getInstalledPrettyVersion(self::$packageName),
            description: fn (): string => 'Static HTML cache, dependency indexing, and cache administration for Capell.',
        );
    }

    public function packageBooted(): void
    {
        if (! $this->isPackageInstalled()) {
            return;
        }

        $this
            ->registerPageCacheDisk()
            ->registerMiddlewareAliases()
            ->registerAdminBridge()
            ->registerAdminExtenders()
            ->registerModelInvalidationHooks()
            ->registerCommands()
            ->registerOptimization();
    }

    private function isPackageInstalled(): bool
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
        CapellAdmin::bootAdminBridges(self::$packageName);

        return $this;
    }

    private function registerAdminExtenders(): self
    {
        $this->app->tag(PageCachePageTableExtender::class, PageTableExtender::TAG);

        return $this;
    }

    private function registerModelInvalidationHooks(): self
    {
        foreach ([Page::class, Translation::class, PageUrl::class] as $modelClass) {
            $modelClass::created(fn (Model $model): mixed => ClearAllHtmlCacheAction::run());
            $modelClass::deleted(fn (Model $model): mixed => ClearAllHtmlCacheAction::run());
        }

        foreach (CapellCore::getModels() as $modelClass) {
            $modelClass::updated(fn (Model $model): mixed => ClearCachedUrlsForModelAction::dispatchAfterResponse($model));
        }

        return $this;
    }

    private function registerCommands(): self
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                StaticSiteCommand::class,
            ]);
        }

        return $this;
    }

    private function registerOptimization(): self
    {
        $this->optimizes(clear: ClearCache::class, key: 'html-page-cache');

        return $this;
    }
}
