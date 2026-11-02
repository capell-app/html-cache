<?php

declare(strict_types=1);

use Capell\Core\Models\SiteDomain;
use Capell\HtmlCache\Actions\ExpireOverdueStaleCachedUrlsAction;
use Capell\HtmlCache\Actions\MarkAllCachedUrlsStaleAction;
use Capell\HtmlCache\Actions\PurgeEdgeCacheAction;
use Capell\HtmlCache\Data\EdgeCachePurgeData;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Models\StaleCachedUrl;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Capell\HtmlCache\Support\Cache\HtmlFrontendOutputCacheInvalidator;
use Capell\HtmlCache\Support\Cache\RefreshWorkerHeartbeat;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Symfony\Component\HttpFoundation\Response;

uses(HtmlCacheTestCase::class);

beforeEach(function (): void {
    Storage::fake('page_cache');
    Queue::fake();
    Cache::flush();
    config()->set('capell-html-cache.enabled', true);
    config()->set('capell-html-cache.write_enabled', true);
    config()->set('capell-html-cache.hit_recording.enabled', false);
    config()->set('capell-html-cache.request_coalescing.enabled', true);
    config()->set('capell-html-cache.request_coalescing.wait_seconds', 1);
    config()->set('capell-html-cache.render_budget.enabled', true);
    config()->set('capell-html-cache.render_budget.max_concurrent', 1);
    config()->set('capell-html-cache.render_budget.wait_seconds', 2);
    Sleep::fake(syncWithCarbon: true);
    $this->freezeTime();
});

afterEach(function (): void {
    Sleep::fake(false);
    $this->travelBack();
});

/**
 * Starts a cold render that holds its render slot until the fiber is resumed.
 *
 * @param  array{renders: int}  $counter
 * @return Fiber<mixed, mixed, mixed, mixed>
 */
function heldBudgetRender(string $url, array &$counter): Fiber
{
    $request = Request::create($url);
    app()->instance('request', $request);
    $fiber = new Fiber(function () use ($request, &$counter): Response {
        return resolve(HtmlCacheMiddleware::class)->handle($request, function () use (&$counter, $request): Response {
            $counter['renders']++;
            Fiber::suspend();

            return response('Rendered ' . $request->path(), 200, ['Content-Type' => 'text/html']);
        });
    });
    $fiber->start();

    return $fiber;
}

/** @param  array{renders: int}  $counter */
function budgetRequest(string $url, array &$counter): Response
{
    $request = Request::create($url);
    app()->instance('request', $request);

    return resolve(HtmlCacheMiddleware::class)->handle($request, function () use (&$counter, $request): Response {
        $counter['renders']++;

        return response('Rendered ' . $request->path(), 200, ['Content-Type' => 'text/html']);
    });
}

it('answers distinct cold misses cheaply while every render slot is busy, then renders once a slot frees', function (): void {
    SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $counter = ['renders' => 0];
    $leader = heldBudgetRender('https://example.test/slow', $counter);

    try {
        foreach (['/one', '/two', '/three'] as $path) {
            $startedAt = now();
            $response = budgetRequest('https://example.test' . $path, $counter);

            expect($counter['renders'])->toBe(1)
                ->and($response->getStatusCode())->toBe(503)
                ->and($response->headers->get('Retry-After'))->toBe('1')
                ->and($response->headers->get('Cache-Control'))->toContain('private', 'no-store')
                ->and($startedAt->diffInSeconds(now()))->toBeLessThanOrEqual(2.0)
                // A refusal rendered nothing, so it must not mark the URL uncacheable.
                ->and(Cache::get('capell-html-cache:render:uncacheable:' . hash('sha256', 'https://example.test' . $path)))->toBeNull();
        }
    } finally {
        $leader->resume();
    }

    expect(budgetFiberResponse($leader)->getStatusCode())->toBe(200);

    $after = budgetRequest('https://example.test/one', $counter);
    $hit = budgetRequest('https://example.test/one', $counter);

    expect($counter['renders'])->toBe(2)
        ->and($after->getContent())->toBe('Rendered one')
        ->and($hit->headers->get('X-Frontend-Cache'))->toBe('HIT');
});

it('serves an existing copy without a render slot while the budget is exhausted', function (): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    Storage::disk('page_cache')->put(resolve(HtmlCachePathResolver::class)->pathForUrl('/pricing', $domain), 'Previous pricing copy');
    $counter = ['renders' => 0];
    $leader = heldBudgetRender('https://example.test/slow', $counter);

    try {
        $response = budgetRequest('https://example.test/pricing', $counter);
    } finally {
        $leader->resume();
    }

    expect($counter['renders'])->toBe(1)
        ->and($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('Previous pricing copy')
        ->and($response->headers->get('X-Frontend-Cache'))->toBe('HIT');
});

it('runs up to the configured number of distinct renders at once', function (): void {
    config()->set('capell-html-cache.render_budget.max_concurrent', 2);
    SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $counter = ['renders' => 0];
    $first = heldBudgetRender('https://example.test/first', $counter);
    $second = heldBudgetRender('https://example.test/second', $counter);

    try {
        $third = budgetRequest('https://example.test/third', $counter);
    } finally {
        $first->resume();
        $second->resume();
    }

    expect($counter['renders'])->toBe(2)
        ->and($third->getStatusCode())->toBe(503)
        ->and(budgetFiberResponse($first)->getStatusCode())->toBe(200)
        ->and(budgetFiberResponse($second)->getStatusCode())->toBe(200);
});

it('renders normally when the render budget is disabled', function (): void {
    config()->set('capell-html-cache.render_budget.enabled', false);
    SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $counter = ['renders' => 0];
    $leader = heldBudgetRender('https://example.test/slow', $counter);

    try {
        $response = budgetRequest('https://example.test/other', $counter);
    } finally {
        $leader->resume();
    }

    expect($counter['renders'])->toBe(2)
        ->and($response->getStatusCode())->toBe(200);
});

it('derives the default render budget from half of the PHP-FPM pool size', function (?string $maxChildren, int $expected): void {
    $previous = getenv('CAPELL_FPM_MAX_CHILDREN');
    putenv('CAPELL_HTML_CACHE_MAX_CONCURRENT_RENDERS');
    $maxChildren === null ? putenv('CAPELL_FPM_MAX_CHILDREN') : putenv('CAPELL_FPM_MAX_CHILDREN=' . $maxChildren);

    try {
        $config = require dirname(__DIR__, 2) . '/config/capell-html-cache.php';
    } finally {
        $previous === false ? putenv('CAPELL_FPM_MAX_CHILDREN') : putenv('CAPELL_FPM_MAX_CHILDREN=' . $previous);
    }

    expect($config['render_budget']['max_concurrent'])->toBe($expected);
})->with([
    'unset falls back to half of 8' => [null, 4],
    'pool of 8' => ['8', 4],
    'pool of 12' => ['12', 6],
    'pool of 1 keeps one slot' => ['1', 1],
    'non-numeric falls back' => ['many', 4],
]);

function budgetTrackedUrl(SiteDomain $domain, string $path): CachedModelUrl
{
    $url = sprintf('%s://%s%s', $domain->scheme, $domain->domain, $path);

    return CachedModelUrl::query()->create([
        'url' => $url,
        'url_hash' => CachedModelUrl::hashUrl($url),
        'path' => $path,
        'site_id' => $domain->site_id,
        'site_domain_id' => $domain->getKey(),
        'language_id' => $domain->language_id,
        'cacheable_type' => $domain->getMorphClass(),
        'cacheable_id' => $domain->getKey(),
        'cached_at' => now(),
        'last_seen_at' => now(),
    ]);
}

it('keeps published copies and re-queues refreshed rows when site-wide output changes and stale copies can be refreshed', function (string $mode, string $queueDriver): void {
    config()->set('capell-html-cache.invalidation.mode', $mode);
    config()->set('capell-html-cache.origin_stale_while_revalidate.enabled', true);
    config()->set('capell-html-cache.origin_stale_while_revalidate.connection', null);
    config()->set('queue.default', 'budget-test');
    config()->set('queue.connections.budget-test', ['driver' => $queueDriver]);
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    budgetTrackedUrl($domain, '/pricing');
    resolve(RefreshWorkerHeartbeat::class)->touch('budget-test');
    $path = resolve(HtmlCachePathResolver::class)->pathForUrl('/pricing', $domain);
    Storage::disk('page_cache')->put($path, 'Published pricing copy');

    (new HtmlFrontendOutputCacheInvalidator)->invalidateAll();

    $stale = StaleCachedUrl::query()->sole();

    expect(Storage::disk('page_cache')->get($path))->toBe('Published pricing copy')
        ->and(CachedModelUrl::query()->count())->toBe(1)
        ->and($stale->status)->toBe(StaleCachedUrl::STATUS_PENDING)
        ->and($stale->reason)->toBe('frontend_output_changed')
        ->and($stale->cache_path)->toBe($path);
})->with([
    'origin refresh on a durable queue' => ['instant', 'database'],
    'scheduled invalidation' => ['scheduled', 'sync'],
]);

it('still clears every copy when stale copies could never be refreshed outside a request', function (): void {
    config()->set('capell-html-cache.invalidation.mode', 'instant');
    config()->set('queue.default', 'budget-test');
    config()->set('queue.connections.budget-test', ['driver' => 'sync']);
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    budgetTrackedUrl($domain, '/pricing');
    $path = resolve(HtmlCachePathResolver::class)->pathForUrl('/pricing', $domain);
    Storage::disk('page_cache')->put($path, 'Published pricing copy');

    (new HtmlFrontendOutputCacheInvalidator)->invalidateAll();

    expect(Storage::disk('page_cache')->exists($path))->toBeFalse()
        ->and(CachedModelUrl::query()->count())->toBe(0)
        ->and(StaleCachedUrl::query()->count())->toBe(0);
});

it('clears every copy and purges the edge when a durable queue has no live worker', function (): void {
    config()->set('capell-html-cache.invalidation.mode', 'instant');
    config()->set('capell-html-cache.origin_stale_while_revalidate.connection', null);
    config()->set('queue.default', 'budget-test');
    config()->set('queue.connections.budget-test', ['driver' => 'database']);
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    budgetTrackedUrl($domain, '/pricing');
    $path = resolve(HtmlCachePathResolver::class)->pathForUrl('/pricing', $domain);
    Storage::disk('page_cache')->put($path, 'Published pricing copy');

    (new HtmlFrontendOutputCacheInvalidator)->invalidateAll();

    expect(Storage::disk('page_cache')->exists($path))->toBeFalse()
        ->and(StaleCachedUrl::query()->count())->toBe(0);
    PurgeEdgeCacheAction::assertPushed(
        fn (PurgeEdgeCacheAction $action, array $parameters): bool => $parameters[0] instanceof EdgeCachePurgeData && $parameters[0]->purgeAll === true,
    );
});

it('treats a worker heartbeat as live only while it is recent', function (): void {
    $heartbeat = resolve(RefreshWorkerHeartbeat::class);
    expect($heartbeat->isAlive('budget-test'))->toBeFalse();

    $heartbeat->record(new Looping('budget-test', 'default'));
    expect($heartbeat->isAlive('budget-test'))->toBeTrue();

    $this->travel(121)->seconds();
    expect($heartbeat->isAlive('budget-test'))->toBeFalse();
});

it('clears a stale copy that no refresh replaced within the maximum stale age and purges the edge', function (): void {
    config()->set('capell-html-cache.invalidation.mode', 'instant');
    config()->set('capell-html-cache.origin_stale_while_revalidate.max_stale_age_seconds', 900);
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    budgetTrackedUrl($domain, '/pricing');
    $path = resolve(HtmlCachePathResolver::class)->pathForUrl('/pricing', $domain);
    Storage::disk('page_cache')->put($path, 'Published pricing copy');
    MarkAllCachedUrlsStaleAction::run('frontend_output_changed');

    $this->travel(899)->seconds();
    expect(ExpireOverdueStaleCachedUrlsAction::run())->toBe(0)
        ->and(Storage::disk('page_cache')->exists($path))->toBeTrue();

    $this->travel(2)->seconds();
    expect(ExpireOverdueStaleCachedUrlsAction::run())->toBe(1)
        ->and(Storage::disk('page_cache')->exists($path))->toBeFalse()
        ->and(StaleCachedUrl::query()->sole()->status)->toBe(StaleCachedUrl::STATUS_NOT_APPLICABLE);
    PurgeEdgeCacheAction::assertPushed(fn (): bool => true);
});

/** @param  Fiber<mixed, mixed, mixed, mixed>  $fiber */
function budgetFiberResponse(Fiber $fiber): Response
{
    $response = $fiber->getReturn();
    throw_unless($response instanceof Response, RuntimeException::class, 'Expected the held render to return a response.');

    return $response;
}
