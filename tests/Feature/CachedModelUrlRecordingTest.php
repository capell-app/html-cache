<?php

declare(strict_types=1);

use Capell\Core\Events\FrontendSurrogateKeysInvalidated;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Translation;
use Capell\HtmlCache\Actions\ClearCachedUrlAction;
use Capell\HtmlCache\Actions\ClearCachedUrlsForModelAction;
use Capell\HtmlCache\Actions\MarkCachedUrlsForModelStaleAction;
use Capell\HtmlCache\Actions\RecordCachedModelUrlsAction;
use Capell\HtmlCache\Jobs\RegisterCachedModelUrlsJob;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Models\StaleCachedUrl;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Capell\HtmlCache\Support\ModelServing\RetrievedModelStore;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

require_once dirname(__DIR__) . '/Support/CachedModelUrlsTestSupport.php';

uses(HtmlCacheTestCase::class);

/**
 * @return array{SiteDomain, Page}
 */
function htmlCacheCreateDomainAndPage(string $domain = 'example.test'): array
{
    $result = EloquentModel::withoutEvents(function () use ($domain): array {
        $siteDomain = SiteDomain::factory()->create([
            'scheme' => 'https',
            'domain' => $domain,
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

    [$siteDomain, $page] = $result;

    throw_unless($siteDomain instanceof SiteDomain, RuntimeException::class, 'Expected site domain fixture.');
    throw_unless($page instanceof Page, RuntimeException::class, 'Expected page fixture.');

    return [$siteDomain, $page];
}

function htmlCacheCreateCachedModelUrl(string $url, SiteDomain $siteDomain, Page $page): CachedModelUrl
{
    return CachedModelUrl::query()->create([
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
}

/**
 * @return array<string, array<int, int|string>>
 */
function htmlCacheModelMap(EloquentModel ...$models): array
{
    $map = [];

    foreach ($models as $model) {
        $key = $model->getKey();

        throw_unless(is_int($key) || is_string($key), RuntimeException::class, 'Expected model key to be scalar.');

        $map[$model->getMorphClass()][] = $key;
    }

    return $map;
}

it('ignores morph pivot relations when tracking rendered models', function (): void {
    Relation::requireMorphMap();

    $page = new Page;
    $page->id = 123;
    $page->exists = true;

    $pivot = new MorphPivot;
    $pivot->setRawAttributes(['tag_id' => 1], true);
    $pivot->exists = true;

    $page->setRelation('pivot', $pivot);

    $store = new RetrievedModelStore;
    $store->track($page);

    expect($store->tracked($page->getMorphClass()))->toBe(1);
});

it('records rendered models against a cached url and removes stale model links', function (): void {
    [$siteDomain, $page] = htmlCacheCreateDomainAndPage();
    $translation = $page->translations()->where('language_id', $siteDomain->language_id)->first();

    expect($translation)->toBeInstanceOf(Translation::class);
    throw_unless($translation instanceof Translation, RuntimeException::class, 'Expected page translation fixture.');

    $url = 'https://example.test/about';

    RecordCachedModelUrlsAction::run($url, htmlCacheModelMap($page, $translation));

    expect(CachedModelUrl::query()->where('url', $url)->count())->toBe(2)
        ->and(CachedModelUrl::query()->where('url', $url)->first())
        ->site_id->toBe($siteDomain->site_id)
        ->site_domain_id->toBe($siteDomain->getKey())
        ->language_id->toBe($siteDomain->language_id)
        ->path->toBe('/about');

    RecordCachedModelUrlsAction::run($url, htmlCacheModelMap($page));

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

it('clears cached files and table rows for invalidated site surrogate keys', function (): void {
    Storage::fake('page_cache');

    [$firstSiteDomain, $firstPage] = htmlCacheCreateDomainAndPage();
    [$secondSiteDomain, $secondPage] = htmlCacheCreateDomainAndPage('other.test');

    $firstUrl = 'https://example.test/about';
    $secondUrl = 'https://other.test/about';
    $firstCachePath = resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $firstSiteDomain);
    $secondCachePath = resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $secondSiteDomain);

    Storage::disk('page_cache')->put($firstCachePath, 'first cached page');
    Storage::disk('page_cache')->put($secondCachePath, 'second cached page');

    htmlCacheCreateCachedModelUrl($firstUrl, $firstSiteDomain, $firstPage);
    htmlCacheCreateCachedModelUrl($secondUrl, $secondSiteDomain, $secondPage);

    event(new FrontendSurrogateKeysInvalidated(['site-' . $firstSiteDomain->site_id]));

    expect(Storage::disk('page_cache')->exists($firstCachePath))->toBeFalse()
        ->and(CachedModelUrl::query()->where('url', $firstUrl)->exists())->toBeFalse()
        ->and(Storage::disk('page_cache')->exists($secondCachePath))->toBeTrue()
        ->and(CachedModelUrl::query()->where('url', $secondUrl)->exists())->toBeTrue();
});

it('does not clear unrelated cached html when a non-route core model is created', function (): void {
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

    Language::factory()->create();

    expect(Storage::disk('page_cache')->exists($cachePath))->toBeTrue()
        ->and(CachedModelUrl::query()->where('url', $url)->exists())->toBeTrue();
});

it('does not clear unrelated cached html when a translation is created', function (): void {
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

    Translation::factory()
        ->translatable($page)
        ->language(Language::factory()->create())
        ->create();

    expect(Storage::disk('page_cache')->exists($cachePath))->toBeTrue()
        ->and(CachedModelUrl::query()->where('url', $url)->exists())->toBeTrue();
});

it('marks model cached urls stale in scheduled invalidation mode without deleting the current cache', function (): void {
    Storage::fake('page_cache');
    config()->set('capell-html-cache.invalidation.mode', 'scheduled');

    [$siteDomain, $page] = htmlCacheCreateDomainAndPage();
    $url = 'https://example.test/about';
    $cachePath = resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $siteDomain);

    Storage::disk('page_cache')->put($cachePath, 'old cached page');
    htmlCacheCreateCachedModelUrl($url, $siteDomain, $page);

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

    throw_if($route === null, RuntimeException::class, 'Expected frontend route to be registered.');

    $middleware = $route->gatherMiddleware();
    $frontendCachePosition = array_search('frontend.cache', $middleware, true);
    $webPosition = array_search('web', $middleware, true);
    $frontendResolvePosition = array_search('frontend.resolve', $middleware, true);
    $modelEventsPosition = array_search('frontend.model_events', $middleware, true);
    $anonymousCacheableRenderPosition = array_search('frontend.anonymous_cacheable_render', $middleware, true);

    assert(is_int($frontendCachePosition));
    assert(is_int($webPosition));
    assert(is_int($frontendResolvePosition));
    assert(is_int($modelEventsPosition));
    assert(is_int($anonymousCacheableRenderPosition));

    expect($middleware)
        ->toContain('frontend.cache')
        ->toContain('frontend.model_events')
        ->toContain('frontend.no_session_cookies_on_cache')
        ->and($frontendCachePosition)
        ->toBeGreaterThan($webPosition)
        ->and($frontendCachePosition)
        ->toBeLessThan($frontendResolvePosition)
        ->and($modelEventsPosition)
        ->toBeGreaterThan($anonymousCacheableRenderPosition);
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

it('clears cached urls for a page and other urls that recorded the page as a dependency', function (): void {
    Storage::fake('page_cache');

    $siteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => null,
    ]);
    $page = Page::factory()
        ->recycle($siteDomain->site)
        ->withTranslations()
        ->create([
            'visible_from' => now()->subDay(),
            'visible_until' => null,
        ]);
    $ownUrl = 'https://example.test/unpublished-page';
    $dependentUrl = 'https://example.test/page-that-uses-unpublished-page';
    $ownCachePath = resolve(HtmlCachePathResolver::class)->pathForUrl('/unpublished-page', $siteDomain);
    $dependentCachePath = resolve(HtmlCachePathResolver::class)->pathForUrl('/page-that-uses-unpublished-page', $siteDomain);

    Storage::disk('page_cache')->put($ownCachePath, 'cached unpublished page');
    Storage::disk('page_cache')->put($dependentCachePath, 'cached dependent page');

    foreach ([
        [$ownUrl, '/unpublished-page'],
        [$dependentUrl, '/page-that-uses-unpublished-page'],
    ] as [$url, $path]) {
        CachedModelUrl::query()->create([
            'url' => $url,
            'url_hash' => CachedModelUrl::hashUrl($url),
            'path' => $path,
            'site_id' => $siteDomain->site_id,
            'site_domain_id' => $siteDomain->getKey(),
            'language_id' => $siteDomain->language_id,
            'cacheable_type' => $page->getMorphClass(),
            'cacheable_id' => $page->getKey(),
            'cached_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    $page->visible_until = now();
    $page->save();

    expect(Storage::disk('page_cache')->exists($ownCachePath))->toBeFalse()
        ->and(Storage::disk('page_cache')->exists($dependentCachePath))->toBeFalse()
        ->and(CachedModelUrl::query()->whereIn('url', [$ownUrl, $dependentUrl])->exists())->toBeFalse();
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
