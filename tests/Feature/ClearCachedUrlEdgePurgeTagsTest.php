<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\Core\Models\SiteDomain;
use Capell\HtmlCache\Actions\ClearCachedUrlAction;
use Capell\HtmlCache\Actions\ClearCachedUrlsForSurrogateKeysAction;
use Capell\HtmlCache\Actions\PurgeEdgeCacheAction;
use Capell\HtmlCache\Actions\ResolveEdgeCacheTagsAction;
use Capell\HtmlCache\Data\EdgeCachePurgeData;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Lorisleiva\Actions\Decorators\JobDecorator;

require_once dirname(__DIR__) . '/Support/CachedModelUrlsTestSupport.php';

uses(HtmlCacheTestCase::class);

/**
 * Every public response carries the site- and lang- edge tags, so purging
 * either flushes the whole site and spends the provider's scarce tag-purge
 * allowance. Clearing one URL must purge that URL and its page tag only.
 */
it('purges only the cleared url and its page tag at the edge, never the site-wide tags', function (): void {
    Storage::fake('page_cache');
    Queue::fake();

    $siteDomain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $page = Page::factory()->recycle($siteDomain->site)->withTranslations()->create();
    $url = 'https://example.test/about';

    // Recorded rows store the morph alias, not the class name.
    expect($page->getMorphClass())->not->toBe(Page::class);

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
    CachedModelUrl::query()->create([
        'url' => $url,
        'url_hash' => CachedModelUrl::hashUrl($url),
        'path' => '/about',
        'site_id' => $siteDomain->site_id,
        'site_domain_id' => $siteDomain->getKey(),
        'language_id' => $siteDomain->language_id,
        'cacheable_type' => $siteDomain->site->getMorphClass(),
        'cacheable_id' => $siteDomain->site_id,
        'cached_at' => now(),
        'last_seen_at' => now(),
    ]);

    ClearCachedUrlAction::run($url, $siteDomain);

    PurgeEdgeCacheAction::assertPushed(
        fn (PurgeEdgeCacheAction $action, array $parameters): bool => $parameters[0] instanceof EdgeCachePurgeData
            && $parameters[0]->urls === [$url]
            && $parameters[0]->tags === ['page-' . $page->getKey(), ResolveEdgeCacheTagsAction::urlTag($url)]
            && ! $parameters[0]->purgeAll
            // URL-scoped: the tags may be dropped when tag purges cannot be paced.
            && ($parameters[1] ?? false) === true,
    );
});

it('purges the url and its path tag, covering query variants, when the cleared rows record no page', function (): void {
    Storage::fake('page_cache');
    Queue::fake();

    $siteDomain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $url = 'https://example.test/contact';

    CachedModelUrl::query()->create([
        'url' => $url,
        'url_hash' => CachedModelUrl::hashUrl($url),
        'path' => '/contact',
        'site_id' => $siteDomain->site_id,
        'site_domain_id' => $siteDomain->getKey(),
        'language_id' => $siteDomain->language_id,
        'cacheable_type' => $siteDomain->site->getMorphClass(),
        'cacheable_id' => $siteDomain->site_id,
        'cached_at' => now(),
        'last_seen_at' => now(),
    ]);

    ClearCachedUrlAction::run($url, $siteDomain);

    PurgeEdgeCacheAction::assertPushed(
        fn (PurgeEdgeCacheAction $action, array $parameters): bool => $parameters[0] instanceof EdgeCachePurgeData
            && $parameters[0]->urls === [$url]
            && $parameters[0]->tags === [ResolveEdgeCacheTagsAction::urlTag($url)],
    );
});

it('tags every cached response with a path tag shared by its query variants', function (): void {
    Storage::fake('page_cache');
    config()->set('capell-html-cache.enabled', true);
    config()->set('capell-html-cache.write_enabled', true);
    config()->set('capell-html-cache.cache_ttl', '3600');
    config()->set('capell-html-cache.filesystem_ttl_seconds', 3600);
    config()->set('capell-html-cache.stateless_pagination.enabled', true);
    config()->set('capell-html-cache.stateless_pagination.params', ['page']);
    SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $request = Request::create('https://example.test/performance/receipts?page=2', Symfony\Component\HttpFoundation\Request::METHOD_GET);
    app()->instance('request', $request);

    $response = resolve(HtmlCacheMiddleware::class)->handle(
        $request,
        fn (): Response => response('receipts page two', 200, ['Content-Type' => 'text/html']),
    );

    $tags = explode(',', (string) $response->headers->get('Cache-Tag'));

    expect($tags)->toContain(ResolveEdgeCacheTagsAction::urlTag('https://example.test/performance/receipts'))
        ->and(ResolveEdgeCacheTagsAction::urlTag('https://example.test/performance/receipts/'))
        ->toBe(ResolveEdgeCacheTagsAction::urlTag('https://example.test/performance/receipts?page=2'))
        ->and(ResolveEdgeCacheTagsAction::urlTag('https://example.test/performance/receipts'))
        ->not->toBe(ResolveEdgeCacheTagsAction::urlTag('https://other.test/performance/receipts'))
        ->not->toBe(ResolveEdgeCacheTagsAction::urlTag('https://example.test/performance'));
});

it('purges the retired url and its query variants at the edge when a cached url turns private', function (): void {
    Storage::fake('page_cache');
    Queue::fake();
    config()->set('capell-html-cache.request_coalescing.enabled', false);

    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    bindHtmlCacheFrontendContext(Page::factory()->recycle($domain->site)->withTranslations()->create());
    $request = Request::create('https://example.test/page');
    $request->attributes->set(HtmlCacheMiddleware::BYPASS_CACHE_READ_ATTRIBUTE, true);
    app()->instance('request', $request);
    Storage::disk('page_cache')->put('https.example.test/page.html', 'previous public output');

    resolve(HtmlCacheMiddleware::class)->handle(
        $request,
        fn (): Response => response('now private', 200, ['Content-Type' => 'text/html', 'Cache-Control' => 'private']),
    );

    expect(Storage::disk('page_cache')->exists('https.example.test/page.html'))->toBeFalse();
    PurgeEdgeCacheAction::assertPushed(
        fn (PurgeEdgeCacheAction $action, array $parameters): bool => $parameters[0] instanceof EdgeCachePurgeData
            && $parameters[0]->urls === ['https://example.test/page']
            && $parameters[0]->tags === [ResolveEdgeCacheTagsAction::urlTag('https://example.test/page')]
            && ($parameters[1] ?? false) === true,
    );
});

/** @return list<EdgeCachePurgeData> */
function pushedEdgePurges(): array
{
    $queue = Queue::getFacadeRoot();

    if (! $queue instanceof QueueFake) {
        throw new RuntimeException('Fake the queue before reading pushed edge purges.');
    }

    $purges = [];

    foreach ($queue->pushed(JobDecorator::class, static fn (JobDecorator $job): bool => $job->decorates(PurgeEdgeCacheAction::class)) as $job) {
        $purge = $job instanceof JobDecorator ? ($job->getParameters()[0] ?? null) : null;

        if ($purge instanceof EdgeCachePurgeData) {
            $purges[] = $purge;
        }
    }

    return $purges;
}

it('sends a site invalidation\'s site tag to the edge once, not per cleared url, reaching unindexed pages', function (): void {
    Storage::fake('page_cache');
    Queue::fake();

    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $siteTag = 'site-' . $domain->site_id;

    foreach (['/a', '/b', '/c'] as $path) {
        CachedModelUrl::query()->create([
            'url' => 'https://example.test' . $path,
            'url_hash' => CachedModelUrl::hashUrl('https://example.test' . $path),
            'path' => $path,
            'site_id' => $domain->site_id,
            'site_domain_id' => $domain->getKey(),
            'language_id' => $domain->language_id,
            'cacheable_type' => $domain->site->getMorphClass(),
            'cacheable_id' => $domain->site_id,
            'cached_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    // A page without model dependencies is cached but never indexed; only the
    // site tag reaches its edge copy.
    $unindexed = resolve(HtmlCachePathResolver::class)->pathForUrl('/unindexed', $domain);
    Storage::disk('page_cache')->put($unindexed, 'previous public output');

    ClearCachedUrlsForSurrogateKeysAction::run([$siteTag]);

    $purges = collect(pushedEdgePurges());
    $sitePurges = $purges->filter(static fn (EdgeCachePurgeData $purge): bool => in_array($siteTag, $purge->tags, true));

    expect(Storage::disk('page_cache')->exists($unindexed))->toBeFalse()
        ->and($sitePurges)->toHaveCount(1)
        ->and($sitePurges->first()?->tags)->toBe([$siteTag])
        ->and($purges->filter(static fn (EdgeCachePurgeData $purge): bool => $purge->urls !== []))->toHaveCount(3)
        ->and($purges->flatMap(static fn (EdgeCachePurgeData $purge): array => $purge->tags)
            ->filter(static fn (string $tag): bool => str_starts_with($tag, 'lang-'))->all())->toBe([]);
});

it('sends a language invalidation\'s lang tag to the edge once', function (): void {
    Queue::fake();

    ClearCachedUrlsForSurrogateKeysAction::run(['lang-en']);

    expect(array_map(static fn (EdgeCachePurgeData $purge): array => $purge->tags, pushedEdgePurges()))->toBe([['lang-en']]);
});
