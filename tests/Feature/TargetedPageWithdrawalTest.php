<?php

declare(strict_types=1);

use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\Frontend\Actions\InvalidateDueScheduledPublicationCachesAction;
use Capell\Frontend\Contracts\RenderedModelTracker;
use Capell\Frontend\Data\Assets\FrontendResourcePlanData;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Data\FrontendRuntimeManifestData;
use Capell\Frontend\Data\PublicPageRenderData;
use Capell\Frontend\Enums\RenderingStrategyEnum;
use Capell\Frontend\Support\Cache\PublicPageRenderDataCache;
use Capell\HtmlCache\Actions\ClearAllHtmlCacheAction;
use Capell\HtmlCache\Actions\PruneHtmlCacheMetadataAction;
use Capell\HtmlCache\Actions\PurgeEdgeCacheAction;
use Capell\HtmlCache\Actions\RecordCachedModelUrlsAction;
use Capell\HtmlCache\Actions\ResolveEdgeCacheTagsAction;
use Capell\HtmlCache\Actions\WithdrawCachedUrlsForModelAction;
use Capell\HtmlCache\Actions\WriteRefreshedHtmlCacheFileAction;
use Capell\HtmlCache\Contracts\CachePurger;
use Capell\HtmlCache\Data\EdgeCachePurgeData;
use Capell\HtmlCache\Http\Middleware\EnsureModelEventsRegistered;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Jobs\RegisterCachedModelUrlsJob;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Models\HtmlCacheDependencyTrackingEpoch;
use Capell\HtmlCache\Models\HtmlCacheModelWithdrawal;
use Capell\HtmlCache\Models\StaleCachedUrl;
use Capell\HtmlCache\Support\Cache\HtmlCacheDependencyTracking;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Capell\HtmlCache\Support\Cache\HtmlCachePublicationGuard;
use Capell\HtmlCache\Support\ModelServing\RetrievedModelStore;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

uses(HtmlCacheTestCase::class);

/**
 * Two published pages on one site, each cached at its own URL, plus a cached
 * listing that renders page A.
 *
 * @return array{domain: SiteDomain, a: Page, b: Page, paths: array<string, string>, urls: array<string, string>, purges: ArrayObject<int, EdgeCachePurgeData>}
 */
function targetedWithdrawalSite(string $mode, bool $recordDependencies = true): array
{
    Storage::fake('page_cache');
    config()->set('capell-html-cache.invalidation.mode', $mode);
    config()->set('queue.default', 'sync');
    /** @var ArrayObject<int, EdgeCachePurgeData> $purges */
    $purges = new ArrayObject;
    app()->instance(CachePurger::class, new readonly class($purges) implements CachePurger
    {
        /** @param ArrayObject<int, EdgeCachePurgeData> $purges */
        public function __construct(private ArrayObject $purges) {}

        public function purge(EdgeCachePurgeData $purge): bool
        {
            $this->purges->append($purge);

            return true;
        }
    });

    [$domain, $a, $b] = Model::withoutEvents(function (): array {
        $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'targeted.example.test', 'path' => null]);
        $pages = [];
        foreach (['/page-a', '/page-b'] as $url) {
            $page = Page::factory()->site($domain->site)->withTranslations($domain->language)->create(['visible_from' => now()->subDay(), 'visible_until' => null]);
            PageUrl::factory()->page($page)->site($domain->site)->language($domain->language)->create(['url' => $url]);
            $pages[] = $page;
        }

        return [$domain, ...$pages];
    });

    $resolver = resolve(HtmlCachePathResolver::class);
    $urls = [];
    $paths = [];
    foreach (['a' => '/page-a', 'b' => '/page-b', 'listing' => '/news'] as $name => $path) {
        $urls[$name] = 'https://targeted.example.test' . $path;
        $paths[$name] = $resolver->pathForUrl($path, $domain);
        Storage::disk('page_cache')->put($paths[$name], 'Cached ' . $name);
    }

    if ($recordDependencies) {
        $morph = $a->getMorphClass();
        RecordCachedModelUrlsAction::run($urls['a'], [$morph => [targetedPageId($a)]], CarbonImmutable::now()->subMinute());
        RecordCachedModelUrlsAction::run($urls['b'], [$morph => [targetedPageId($b)]], CarbonImmutable::now()->subMinute());
        RecordCachedModelUrlsAction::run($urls['listing'], [$morph => [targetedPageId($a)]], CarbonImmutable::now()->subMinute());
    }

    return ['domain' => $domain, 'a' => $a, 'b' => $b, 'paths' => $paths, 'urls' => $urls, 'purges' => $purges];
}

function targetedPageId(Page $page): int
{
    $key = $page->getKey();

    return is_numeric($key) ? (int) $key : throw new UnexpectedValueException('Pages have integer keys.');
}

function withdrawPage(Page $page, string $change): void
{
    if ($change === 'unpublished') {
        $page->update(['visible_until' => now()->subSecond()]);

        return;
    }

    $page->delete();
}

it('withdraws only the page and the cached URLs that render it', function (string $mode, string $change): void {
    $site = targetedWithdrawalSite($mode);

    withdrawPage($site['a'], $change);

    $disk = Storage::disk('page_cache');
    expect($disk->exists($site['paths']['a']))->toBeFalse()
        ->and($disk->exists($site['paths']['listing']))->toBeFalse()
        ->and($disk->exists($site['paths']['b']))->toBeTrue();

    // One batched purge of exactly the withdrawn URLs; never the whole edge.
    expect($site['purges'])->toHaveCount(1)
        ->and($site['purges']->getArrayCopy()[0]->purgeAll)->toBeFalse()
        ->and($site['purges']->getArrayCopy()[0]->urls)->toEqualCanonicalizing([$site['urls']['a'], $site['urls']['listing']])
        // Only URL-scoped tags: a site or language tag would flush every page that carries it.
        ->and($site['purges']->getArrayCopy()[0]->tags)->toEqualCanonicalizing([
            ResolveEdgeCacheTagsAction::urlTag($site['urls']['a']),
            ResolveEdgeCacheTagsAction::urlTag($site['urls']['listing']),
        ]);
})->with(['scheduled', 'instant'])->with(['unpublished', 'deleted']);

it('refuses in-flight renders of the withdrawn page and its dependants while other renders still publish', function (string $change): void {
    $site = targetedWithdrawalSite('scheduled');
    $guard = resolve(HtmlCachePublicationGuard::class);
    $pageA = HtmlCachePublicationGuard::modelKey($site['a']->getMorphClass(), targetedPageId($site['a']));
    $pageB = HtmlCachePublicationGuard::modelKey($site['b']->getMorphClass(), targetedPageId($site['b']));

    $tokens = [
        'a' => $guard->capture(Request::create($site['urls']['a'])),
        'listing' => $guard->capture(Request::create($site['urls']['listing'])),
        'b' => $guard->capture(Request::create($site['urls']['b'])),
        'uncachedEmbed' => $guard->capture(Request::create('https://targeted.example.test/related')),
        'unrelated' => $guard->capture(Request::create('https://targeted.example.test/about')),
    ];

    withdrawPage($site['a'], $change);

    $publishes = static fn (string $name, string ...$models): bool => $guard->publish($tokens[$name], static fn (): bool => true, array_values($models));

    expect($publishes('a', $pageA))->toBeFalse()
        ->and($publishes('listing', $pageA))->toBeFalse()
        // A page that was not cached yet but rendered page A before it was withdrawn.
        ->and($publishes('uncachedEmbed', $pageA, $pageB))->toBeFalse()
        ->and($publishes('b', $pageB))->toBeTrue()
        ->and($publishes('unrelated', $pageB))->toBeTrue();

    $after = $guard->capture(Request::create('https://targeted.example.test/fresh'));
    expect($guard->publish($after, static fn (): bool => true, [$pageA]))->toBeTrue();
})->with(['unpublished', 'deleted']);

it('lets an unrelated page render that is in flight during a withdrawal write its cache file', function (string $change): void {
    $site = targetedWithdrawalSite('scheduled');
    config()->set('capell-html-cache.enabled', true);
    config()->set('capell-html-cache.write_enabled', true);
    config()->set('capell-html-cache.request_coalescing.enabled', false);
    Storage::disk('page_cache')->delete($site['paths']['b']);

    $request = Request::create($site['urls']['b']);
    app()->instance('request', $request);
    $response = resolve(HtmlCacheMiddleware::class)->handle($request, function () use ($site, $change) {
        withdrawPage($site['a'], $change);

        return response('<main>Page B</main>', 200, ['Content-Type' => 'text/html']);
    });

    expect(Storage::disk('page_cache')->exists($site['paths']['b']))->toBeTrue()
        ->and($request->attributes->get(HtmlCacheMiddleware::CACHE_WRITE_SUCCEEDED_ATTRIBUTE))->toBeTrue()
        ->and($response->headers->get('X-Frontend-Cache'))->toBe('MISS');
})->with(['unpublished', 'deleted']);

it('refuses an in-flight render of an uncached page that read the withdrawn page', function (string $change): void {
    $site = targetedWithdrawalSite('scheduled');
    config()->set('capell-html-cache.enabled', true);
    config()->set('capell-html-cache.write_enabled', true);
    config()->set('capell-html-cache.request_coalescing.enabled', false);
    $relatedPath = resolve(HtmlCachePathResolver::class)->pathForUrl('/related', $site['domain']);

    $request = Request::create('https://targeted.example.test/related');
    app()->instance('request', $request);
    $response = resolve(HtmlCacheMiddleware::class)->handle($request, fn () => resolve(EnsureModelEventsRegistered::class)->handle($request, function () use ($site, $change) {
        $embedded = Page::query()->whereKey(targetedPageId($site['a']))->firstOrFail();
        withdrawPage($site['a'], $change);

        return response('<main>Related: ' . targetedPageId($embedded) . '</main>', 200, ['Content-Type' => 'text/html']);
    }));

    expect(Storage::disk('page_cache')->exists($relatedPath))->toBeFalse()
        ->and($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
})->with(['unpublished', 'deleted']);

it('withdraws a page published before the withdrawal whose dependencies are recorded after it', function (): void {
    $site = targetedWithdrawalSite('scheduled');
    $relatedPath = resolve(HtmlCachePathResolver::class)->pathForUrl('/related', $site['domain']);
    Storage::disk('page_cache')->put($relatedPath, 'Rendered with page A');
    $renderedAt = CarbonImmutable::now();

    withdrawPage($site['a'], 'unpublished');
    expect(Storage::disk('page_cache')->exists($relatedPath))->toBeTrue();

    RecordCachedModelUrlsAction::run('https://targeted.example.test/related', [$site['a']->getMorphClass() => [targetedPageId($site['a'])]], $renderedAt);

    expect(Storage::disk('page_cache')->exists($relatedPath))->toBeFalse()
        ->and(Storage::disk('page_cache')->exists($site['paths']['b']))->toBeTrue();
});

it('clears the whole cache and says why when the page is cached but no cached URL records it', function (string $change): void {
    $site = targetedWithdrawalSite('scheduled', recordDependencies: false);
    $log = Log::spy();

    withdrawPage($site['a'], $change);

    $disk = Storage::disk('page_cache');
    expect($disk->exists($site['paths']['a']))->toBeFalse()
        ->and($disk->exists($site['paths']['listing']))->toBeFalse()
        ->and($disk->exists($site['paths']['b']))->toBeFalse()
        ->and($site['purges'])->toHaveCount(1)
        ->and($site['purges']->getArrayCopy()[0]->purgeAll)->toBeTrue();
    $log->shouldHaveReceived('info')
        ->withArgs(static fn (string $message, array $context): bool => $context['reason'] === 'the page is cached but no cached URL records it')
        ->once();
})->with(['unpublished', 'deleted']);

it('clears the whole cache when the page has no served URL left to withdraw', function (): void {
    $site = targetedWithdrawalSite('scheduled');
    Model::withoutEvents(fn () => PageUrl::query()->where('pageable_id', targetedPageId($site['a']))->forceDelete());

    $result = WithdrawCachedUrlsForModelAction::run($site['a']->getMorphClass(), targetedPageId($site['a']));

    expect($result->targeted)->toBeFalse()
        ->and($result->fullClearReason)->toBe('the page has no served URL left to withdraw')
        ->and(Storage::disk('page_cache')->exists($site['paths']['b']))->toBeFalse();
});

it('still clears every page when a site is disabled', function (): void {
    $site = targetedWithdrawalSite('scheduled');

    $site['domain']->site->update(['status' => false]);

    foreach ($site['paths'] as $path) {
        expect(Storage::disk('page_cache')->exists($path))->toBeFalse();
    }
});

it('still clears every page when a page is published, because listings do not record it yet', function (): void {
    $site = targetedWithdrawalSite('scheduled');
    Model::withoutEvents(fn () => $site['a']->update(['visible_from' => now()->addDay()]));

    $site['a']->update(['visible_from' => now()->subMinute()]);

    expect(Storage::disk('page_cache')->exists($site['paths']['b']))->toBeFalse();
});

it('leaves addresses the page stopped using before it was withdrawn', function (string $change): void {
    $site = targetedWithdrawalSite('scheduled');
    Model::withoutEvents(function () use ($site): void {
        $old = PageUrl::factory()->page($site['a'])->site($site['domain']->site)->language($site['domain']->language)->create(['url' => '/old-a']);
        $old->forceFill(['deleted_at' => now()->subWeek()])->save();
    });
    $oldPath = resolve(HtmlCachePathResolver::class)->pathForUrl('/old-a', $site['domain']);
    Storage::disk('page_cache')->put($oldPath, 'Now served by another page');

    withdrawPage($site['a'], $change);

    expect(Storage::disk('page_cache')->exists($site['paths']['a']))->toBeFalse()
        ->and(Storage::disk('page_cache')->exists($oldPath))->toBeTrue();
})->with(['unpublished', 'deleted']);

it('does not withdraw anything again when a page already in the trash is deleted permanently', function (): void {
    $site = targetedWithdrawalSite('scheduled');
    $site['a']->delete();
    $site['purges']->exchangeArray([]);
    Storage::disk('page_cache')->put($site['paths']['b'], 'Cached b');

    Page::query()->withTrashed()->whereKey(targetedPageId($site['a']))->firstOrFail()->forceDelete();

    expect(Storage::disk('page_cache')->exists($site['paths']['b']))->toBeTrue()
        ->and($site['purges']->getArrayCopy())->toBe([]);
});

it('tags every cached response with its URL so its query variants purge together', function (): void {
    expect(ResolveEdgeCacheTagsAction::run(Request::create('https://Targeted.example.test/news?page=2')))
        ->toContain(ResolveEdgeCacheTagsAction::urlTag('https://targeted.example.test/news'))
        ->and(ResolveEdgeCacheTagsAction::urlTag('https://targeted.example.test/news/'))
        ->toBe(ResolveEdgeCacheTagsAction::urlTag('https://targeted.example.test/news?page=2'));
});

it('still withdraws a listing whose later render came from cached render data and did not retrieve the page', function (): void {
    $site = targetedWithdrawalSite('scheduled');

    // A warm public render-data cache serves the listing without loading page A,
    // so this render only retrieves the listing's own page.
    RecordCachedModelUrlsAction::run($site['urls']['listing'], [$site['b']->getMorphClass() => [targetedPageId($site['b'])]]);

    withdrawPage($site['a'], 'unpublished');

    expect(Storage::disk('page_cache')->exists($site['paths']['listing']))->toBeFalse()
        ->and(Storage::disk('page_cache')->exists($site['paths']['b']))->toBeTrue();
});

it('schedules metadata pruning in every invalidation mode and keeps a day of model withdrawals', function (): void {
    expect(config('capell-html-cache.invalidation.mode', 'instant'))->toBe('instant')
        ->and(collect(resolve(Schedule::class)->events())
            ->contains(static fn (ScheduledEvent $event): bool => $event->description === 'capell-html-cache:prune-metadata'))->toBeTrue();

    HtmlCacheModelWithdrawal::query()->insert([
        ['model_key' => 'page:1', 'withdrawn_at' => CarbonImmutable::now()->subHours(25)],
        ['model_key' => 'page:2', 'withdrawn_at' => CarbonImmutable::now()->subHours(23)],
    ]);

    PruneHtmlCacheMetadataAction::run();

    expect(HtmlCacheModelWithdrawal::query()->pluck('model_key')->all())->toBe(['page:2']);
});

it('still purges the URLs a withdrawal removed when a later URL fails', function (): void {
    $site = targetedWithdrawalSite('scheduled');

    expect(fn () => PurgeEdgeCacheAction::batch(static function () use ($site): void {
        PurgeEdgeCacheAction::dispatchAfterCommit(new EdgeCachePurgeData(urls: [$site['urls']['a']]));

        throw new RuntimeException('Second URL failed');
    }))->toThrow(RuntimeException::class, 'Second URL failed');

    expect($site['purges'])->toHaveCount(1)
        ->and($site['purges']->getArrayCopy()[0]->urls)->toBe([$site['urls']['a']]);
});

it('refuses a sweep refresh of a listing that read the page before it was withdrawn', function (): void {
    $site = targetedWithdrawalSite('scheduled');
    $request = Request::create($site['urls']['listing']);
    app()->instance('request', $request);
    resolve(HtmlCachePublicationGuard::class)->capture($request);
    $stale = StaleCachedUrl::query()->create([
        'stale_key' => hash('sha256', $site['urls']['listing']), 'url' => $site['urls']['listing'], 'url_hash' => hash('sha256', $site['urls']['listing']),
        'path' => '/news', 'cache_path' => $site['paths']['listing'], 'error_cache_path' => null, 'reason' => 'manual',
        'status' => StaleCachedUrl::STATUS_PROCESSING, 'claim_token' => 'refresh-claim',
    ]);
    $request->attributes->set(HtmlCachePublicationGuard::RENDERED_MODELS_ATTRIBUTE, [
        HtmlCachePublicationGuard::modelKey($site['a']->getMorphClass(), targetedPageId($site['a'])),
    ]);

    // Withdrawn mid-render, after the listing's own index was already cleared.
    resolve(HtmlCachePublicationGuard::class)->fence([], [HtmlCachePublicationGuard::modelKey($site['a']->getMorphClass(), targetedPageId($site['a']))]);

    expect(WriteRefreshedHtmlCacheFileAction::run(response('<main>Listing with page A</main>'), $stale, $request))->toBeFalse()
        ->and(Storage::disk('page_cache')->get($site['paths']['listing']))->toBe('Cached listing');
});

it('checks for the withdrawal table once however many renders capture and publish', function (): void {
    targetedWithdrawalSite('scheduled');
    $schemaQueries = 0;
    DB::listen(static function ($query) use (&$schemaQueries): void {
        if (str_contains($query->sql, 'sqlite_master') || str_contains($query->sql, 'information_schema')) {
            $schemaQueries++;
        }
    });

    foreach (['/one', '/two', '/three'] as $path) {
        $guard = resolve(HtmlCachePublicationGuard::class);
        $token = $guard->capture(Request::create('https://targeted.example.test' . $path));
        $guard->publish($token, static fn (): bool => true, ['page:1']);
    }

    expect($schemaQueries)->toBeLessThanOrEqual(1);
});

it('answers an installed withdrawal table from the request schema probe other packages share', function (): void {
    // A new request, in which another package's probe has already listed the tables.
    app()->forgetScopedInstances();
    expect(resolve(RuntimeSchemaState::class)->hasTable('pages'))->toBeTrue();
    $queries = 0;
    DB::listen(static function () use (&$queries): void {
        $queries++;
    });

    expect(resolve(HtmlCachePublicationGuard::class)->withdrawalsInstalled())->toBeTrue()
        ->and($queries)->toBe(0);
});

it('finds a withdrawal table installed after the shared schema probe found none', function (): void {
    $table = (new HtmlCacheModelWithdrawal)->getTable();
    app()->forgetScopedInstances();
    Schema::rename($table, $table . '_parked');

    expect(resolve(RuntimeSchemaState::class)->hasTable($table))->toBeFalse()
        ->and(resolve(HtmlCachePublicationGuard::class)->withdrawalsInstalled())->toBeFalse();

    Schema::rename($table . '_parked', $table);

    // Absence is never trusted from the shared probe, so publishing still checks withdrawals.
    expect(resolve(HtmlCachePublicationGuard::class)->withdrawalsInstalled())->toBeTrue();
});

it('withdraws a cached URL whose deferred dependency recording failed', function (): void {
    $site = targetedWithdrawalSite('scheduled');
    config()->set('capell-html-cache.model_event_registration_mode', 'deferred');
    // The dependency index refuses writes, as an unavailable database would.
    DB::statement("CREATE TRIGGER refuse_cached_model_urls BEFORE INSERT ON cached_model_urls BEGIN SELECT RAISE(ABORT, 'Database unavailable'); END");
    $store = resolve(RetrievedModelStore::class);
    $store->track($site['a']);

    $store->flushToUrl($site['urls']['listing']);
    app(DeferredCallbackCollection::class)->invoke();

    expect(Storage::disk('page_cache')->exists($site['paths']['listing']))->toBeFalse()
        ->and(Storage::disk('page_cache')->exists($site['paths']['b']))->toBeTrue();
});

it('withdraws a cached URL whose queued dependency recording finally failed', function (): void {
    $site = targetedWithdrawalSite('scheduled');

    (new RegisterCachedModelUrlsJob($site['urls']['listing'], [$site['a']->getMorphClass() => [targetedPageId($site['a'])]]))
        ->failed(new RuntimeException('Database unavailable'));

    expect(Storage::disk('page_cache')->exists($site['paths']['listing']))->toBeFalse()
        ->and(Storage::disk('page_cache')->exists($site['paths']['b']))->toBeTrue();
});

it('withdraws nothing for a page that was never cached and that nothing records', function (string $change): void {
    $site = targetedWithdrawalSite('scheduled', recordDependencies: false);
    Storage::disk('page_cache')->delete($site['paths']['a']);

    withdrawPage($site['a'], $change);

    expect(Storage::disk('page_cache')->exists($site['paths']['b']))->toBeTrue()
        ->and(Storage::disk('page_cache')->exists($site['paths']['listing']))->toBeTrue()
        ->and($site['purges']->getArrayCopy())->toBe([]);
})->with(['unpublished', 'deleted']);

it('withdraws only the expired page and the cached URLs that render it when its scheduled expiry passes', function (string $mode): void {
    $site = targetedWithdrawalSite($mode);
    // The expiry was scheduled earlier; reaching it changes no row, so only the scheduler sees it.
    $site['a']->forceFill(['visible_until' => now()->subSeconds(30)])->saveQuietly();

    expect(InvalidateDueScheduledPublicationCachesAction::run())->toBe(1);

    $disk = Storage::disk('page_cache');
    expect($disk->exists($site['paths']['a']))->toBeFalse()
        ->and($disk->exists($site['paths']['listing']))->toBeFalse()
        ->and($disk->exists($site['paths']['b']))->toBeTrue()
        ->and($site['purges'])->toHaveCount(1)
        ->and($site['purges']->getArrayCopy()[0]->purgeAll)->toBeFalse()
        ->and($site['purges']->getArrayCopy()[0]->urls)->toEqualCanonicalizing([$site['urls']['a'], $site['urls']['listing']]);
})->with(['scheduled', 'instant']);

it('records the page a listing shows when the listing is served from cached render data after its dependencies were cleared', function (): void {
    $site = targetedWithdrawalSite('scheduled');
    config()->set('capell-frontend.public_render_data_cache', true);
    config()->set('capell-html-cache.model_event_registration_mode', 'sync');
    $listing = Model::withoutEvents(fn (): Page => Page::factory()->site($site['domain']->site)->withTranslations($site['domain']->language)->create(['visible_from' => now()->subDay()]));
    $runtime = FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly);
    $context = new FrontendRenderContextData(
        page: $listing,
        site: $site['domain']->site,
        language: $site['domain']->language,
        layout: Layout::query()->find($listing->layout_id),
        theme: $site['domain']->site->theme,
        runtimeManifest: $runtime,
    );
    $renders = 0;
    $render = function () use (&$renders, $context, $runtime, $site): PublicPageRenderData {
        $renders++;
        // Rendering the listing loads the page it shows.
        resolve(RenderedModelTracker::class)->track(Page::query()->findOrFail(targetedPageId($site['a'])));

        return new PublicPageRenderData(
            page: $context->page,
            site: $context->site,
            language: $context->language,
            layout: $context->layout,
            theme: $context->theme,
            layoutGraph: null,
            runtimeManifest: $runtime,
            resourcePlan: new FrontendResourcePlanData([], [], [], [], [], [], [], hash('sha256', 'empty')),
            surrogateKeys: [],
        );
    };
    $renderListing = function () use ($context, $render, $site): void {
        app()->forgetScopedInstances();
        resolve(PublicPageRenderDataCache::class)->remember($context, $render);
        resolve(RetrievedModelStore::class)->flushToUrl($site['urls']['listing']);
    };

    $renderListing();
    // An operator clear, or a clear for another model, drops the listing's dependency rows.
    CachedModelUrl::query()->where('url', $site['urls']['listing'])->delete();
    // The listing is rendered again from the warm render data cache.
    $renderListing();

    withdrawPage($site['a'], 'unpublished');

    expect($renders)->toBe(1)
        ->and(Storage::disk('page_cache')->exists($site['paths']['listing']))->toBeFalse()
        ->and(Storage::disk('page_cache')->exists($site['paths']['b']))->toBeTrue();
});

it('clears the whole cache when cached pages may predate dependency tracking of cached render data', function (string $change): void {
    $site = targetedWithdrawalSite('scheduled');
    // A listing cached before this tracking version may show page A without recording it.
    HtmlCacheDependencyTrackingEpoch::query()->delete();
    $legacyPath = resolve(HtmlCachePathResolver::class)->pathForUrl('/legacy-listing', $site['domain']);
    Storage::disk('page_cache')->put($legacyPath, 'Rendered with page A before the cutover');

    withdrawPage($site['a'], $change);

    expect(Storage::disk('page_cache')->exists($legacyPath))->toBeFalse()
        ->and(Storage::disk('page_cache')->exists($site['paths']['b']))->toBeFalse()
        ->and(HtmlCacheDependencyTracking::isCurrent())->toBeTrue();
})->with(['unpublished', 'deleted']);

it('withdraws only the affected pages once every cached page was written after the cutover', function (): void {
    $site = targetedWithdrawalSite('scheduled');
    HtmlCacheDependencyTrackingEpoch::query()->delete();

    // The first full clear after the cutover leaves only pages written with current tracking.
    ClearAllHtmlCacheAction::run();
    foreach (['a', 'b', 'listing'] as $name) {
        Storage::disk('page_cache')->put($site['paths'][$name], 'Rewritten ' . $name);
    }

    $morph = $site['a']->getMorphClass();
    RecordCachedModelUrlsAction::run($site['urls']['a'], [$morph => [targetedPageId($site['a'])]]);
    RecordCachedModelUrlsAction::run($site['urls']['b'], [$morph => [targetedPageId($site['b'])]]);
    RecordCachedModelUrlsAction::run($site['urls']['listing'], [$morph => [targetedPageId($site['a'])]]);

    withdrawPage($site['a'], 'unpublished');

    expect(Storage::disk('page_cache')->exists($site['paths']['a']))->toBeFalse()
        ->and(Storage::disk('page_cache')->exists($site['paths']['listing']))->toBeFalse()
        ->and(Storage::disk('page_cache')->exists($site['paths']['b']))->toBeTrue();
});

it('trusts dependency tracking from the start on a fresh install', function (): void {
    expect(HtmlCacheDependencyTracking::isCurrent())->toBeTrue();

    $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_11_02_000002_create_html_cache_dependency_tracking_epochs_table.php';
    $site = targetedWithdrawalSite('scheduled');
    $migration->down();
    $migration->up();

    // An install that already holds cached pages cannot vouch for them.
    expect(HtmlCacheDependencyTracking::isCurrent())->toBeFalse();
});

it('clears the whole cache when queued registration may not have recorded a cached page', function (string $change): void {
    config()->set('capell-html-cache.model_event_registration_mode', 'async');
    $site = targetedWithdrawalSite('scheduled');
    $relatedPath = resolve(HtmlCachePathResolver::class)->pathForUrl('/related', $site['domain']);
    Storage::disk('page_cache')->put($relatedPath, 'Rendered with page A');

    withdrawPage($site['a'], $change);

    expect(Storage::disk('page_cache')->exists($relatedPath))->toBeFalse()
        ->and($site['purges']->getArrayCopy()[0]->purgeAll)->toBeTrue();
})->with(['unpublished', 'deleted']);
