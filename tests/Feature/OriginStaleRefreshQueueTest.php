<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\Core\Models\SiteDomain;
use Capell\HtmlCache\Actions\ProcessStaleHtmlCacheAction;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Jobs\FlushHtmlCacheHitBatchJob;
use Capell\HtmlCache\Jobs\RefreshOriginStaleCachedUrlJob;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Models\StaleCachedUrl;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Capell\HtmlCache\Support\Cache\HtmlCacheStore;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Testing\Fakes\QueueFake;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Response;

require_once dirname(__DIR__) . '/Support/CachedModelUrlsTestSupport.php';

uses(HtmlCacheTestCase::class);

function queuedOriginStaleRow(): StaleCachedUrl
{
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $page = Page::factory()->recycle($domain->site)->withTranslations()->create();
    bindHtmlCacheFrontendContext($page);
    $url = 'https://example.test/stale';
    $path = resolve(HtmlCachePathResolver::class)->pathForUrl('/stale', $domain);
    Storage::disk('page_cache')->put($path, 'Previous public HTML');

    return StaleCachedUrl::query()->create([
        'url' => $url,
        'url_hash' => CachedModelUrl::hashUrl($url),
        'path' => '/stale',
        'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl($url), $domain->site_id, $domain->getKey(), '/stale'),
        'site_id' => $domain->site_id,
        'site_domain_id' => $domain->getKey(),
        'language_id' => $domain->language_id,
        'cache_path' => $path,
        'status' => StaleCachedUrl::STATUS_PENDING,
    ]);
}

function queuedOriginCacheHit(string $url): Response
{
    $request = Request::create($url);
    app()->instance('request', $request);

    return resolve(HtmlCacheMiddleware::class)->handle($request, static fn (): Response => response('Unexpected cache miss'));
}

function queuedOriginCachePath(StaleCachedUrl $row): string
{
    $path = $row->cache_path;

    if (! is_string($path)) {
        throw new LogicException('The stale-cache fixture requires a cache path.');
    }

    return $path;
}

function queuedOriginRefreshJob(): RefreshOriginStaleCachedUrlJob
{
    $queue = Queue::getFacadeRoot();

    if (! $queue instanceof QueueFake) {
        throw new LogicException('The refresh test requires a fake queue.');
    }

    $job = $queue->pushed(RefreshOriginStaleCachedUrlJob::class)->sole();

    Assert::assertInstanceOf(RefreshOriginStaleCachedUrlJob::class, $job);

    return $job;
}

beforeEach(function (): void {
    Storage::fake('page_cache');
    Cache::flush();
    Queue::fake([RefreshOriginStaleCachedUrlJob::class]);
    config()->set('capell-html-cache.hit_recording.enabled', false);
    config()->set('capell-html-cache.origin_stale_while_revalidate.enabled', true);
    config()->set('capell-html-cache.origin_stale_while_revalidate.connection', 'database');
    config()->set('capell-html-cache.origin_stale_while_revalidate.dispatch_interval_seconds', 30);
});

it('queues one origin refresh across hits and performs no full render at termination', function (): void {
    $row = queuedOriginStaleRow();
    $renders = 0;
    Route::get('/stale', function () use (&$renders): Response {
        $renders++;

        return response('Refreshed public HTML', 200, ['Content-Type' => 'text/html']);
    });
    $dispatcher = resolve(Dispatcher::class);
    Bus::swap(Mockery::mock($dispatcher));
    Bus::shouldReceive('dispatch')->with(Mockery::type(RefreshOriginStaleCachedUrlJob::class))
        ->andReturnUsing(static function (RefreshOriginStaleCachedUrlJob $job) use ($dispatcher): mixed {
            if (Fiber::getCurrent() !== null) {
                Fiber::suspend();
            }

            return $dispatcher->dispatch($job);
        });
    DB::enableQueryLog();
    DB::flushQueryLog();

    $firstHit = new Fiber(static fn (): Response => queuedOriginCacheHit($row->url));
    $firstHit->start();

    try {
        for ($hit = 0; $hit < 10; $hit++) {
            expect(queuedOriginCacheHit($row->url)->getContent())->toBe('Previous public HTML');
        }
    } finally {
        if ($firstHit->isSuspended()) {
            $firstHit->resume();
        }

        Bus::swap($dispatcher);
    }
    app()->terminate();

    expect($renders)->toBe(0)
        ->and(collect(DB::getQueryLog())->filter(static fn (array $query): bool => str_contains($query['query'], 'stale_cached_urls')))->toHaveCount(0)
        ->and($row->refresh()->status)->toBe(StaleCachedUrl::STATUS_PENDING)
        ->and($row->attempts)->toBe(0)
        ->and(Storage::disk('page_cache')->get(queuedOriginCachePath($row)))->toBe('Previous public HTML');
    Queue::assertPushed(RefreshOriginStaleCachedUrlJob::class, 1);

    $job = queuedOriginRefreshJob();
    $job->handle();
    resolve(UniqueLock::class)->release($job);

    expect($renders)->toBe(1)
        ->and($row->refresh()->status)->toBe(StaleCachedUrl::STATUS_PROCESSED)
        ->and(Storage::disk('page_cache')->get(queuedOriginCachePath($row)))->toBe('Refreshed public HTML');

    queuedOriginCacheHit($row->url);
    Queue::assertPushed(RefreshOriginStaleCachedUrlJob::class, 1);
    $this->travel(31)->seconds();
    queuedOriginCacheHit($row->url);
    Queue::assertPushed(RefreshOriginStaleCachedUrlJob::class, 2);
    $this->travel(31)->seconds();
    queuedOriginCacheHit($row->url);
    Queue::assertPushed(RefreshOriginStaleCachedUrlJob::class, 2);
});

it('never regenerates inline when the configured queue is not durable and asynchronous', function (string $driver): void {
    $row = queuedOriginStaleRow();
    config()->set('capell-html-cache.origin_stale_while_revalidate.connection', 'request-worker');
    config()->set('queue.connections.request-worker', ['driver' => $driver]);
    Route::get('/stale', static fn (): Response => response('Inline render', 200, ['Content-Type' => 'text/html']));

    expect(queuedOriginCacheHit($row->url)->getContent())->toBe('Previous public HTML');
    app()->terminate();

    expect($row->refresh()->status)->toBe(StaleCachedUrl::STATUS_PENDING)
        ->and(Storage::disk('page_cache')->get(queuedOriginCachePath($row)))->toBe('Previous public HTML');
    Queue::assertNotPushed(RefreshOriginStaleCachedUrlJob::class);
})->with(['sync', 'deferred', 'background', 'failover']);

it('preserves stale serving and durable retry when the queue broker fails', function (bool $recordHits): void {
    $row = queuedOriginStaleRow();
    config()->set('capell-html-cache.hit_recording.enabled', $recordHits);
    $renders = 0;
    Route::get('/stale', function () use (&$renders): Response {
        $renders++;

        return response('Refreshed after outage', 200, ['Content-Type' => 'text/html']);
    });
    $dispatcher = resolve(Dispatcher::class);
    Bus::swap(Mockery::mock($dispatcher));
    Bus::shouldReceive('dispatch')->once()->with(Mockery::type(RefreshOriginStaleCachedUrlJob::class))
        ->andThrow(new RuntimeException('Broker unavailable'));

    if ($recordHits) {
        Bus::shouldReceive('dispatch')->once()->with(Mockery::type(FlushHtmlCacheHitBatchJob::class))
            ->andThrow(new RuntimeException('Telemetry broker unavailable'));
    }

    for ($hit = 0; $hit < 5; $hit++) {
        expect(queuedOriginCacheHit($row->url)->getContent())->toBe('Previous public HTML');
    }

    expect($renders)->toBe(0)
        ->and($row->refresh()->status)->toBe(StaleCachedUrl::STATUS_PENDING)
        ->and($row->claim_token)->toBeNull()
        ->and($row->attempts)->toBe(0);
    expect(ProcessStaleHtmlCacheAction::run(1, suppressInlineEdgePurge: true))->toBe(1)
        ->and($renders)->toBe(1)
        ->and($row->refresh()->status)->toBe(StaleCachedUrl::STATUS_PROCESSED);
})->with(['without telemetry' => false, 'with telemetry' => true]);

it('preserves stale claim recovery backoff and publication fencing in queued refreshes', function (string $state): void {
    $row = queuedOriginStaleRow();
    $row->forceFill(match ($state) {
        'active claim' => ['status' => StaleCachedUrl::STATUS_PROCESSING, 'claim_token' => 'active', 'attempts' => 1],
        'expired claim' => ['status' => StaleCachedUrl::STATUS_PROCESSING, 'claim_token' => 'expired', 'attempts' => 1, 'updated_at' => now()->subMinutes(16)],
        'retry backoff' => ['status' => StaleCachedUrl::STATUS_FAILED, 'attempts' => 1, 'failed_at' => now()],
        'retry ready' => ['status' => StaleCachedUrl::STATUS_FAILED, 'attempts' => 1, 'failed_at' => now()->subMinutes(6)],
        default => [],
    })->save();
    $renders = 0;
    Route::get('/stale', function () use ($row, $state, &$renders): Response {
        $renders++;

        if ($state === 'reclaimed during render') {
            StaleCachedUrl::query()->whereKey($row->getKey())->update(['claim_token' => 'new-owner']);
        } elseif ($state === 'invalidated during render') {
            resolve(HtmlCacheStore::class)->deleteAll();
        }

        return response('Queued fresh HTML', 200, ['Content-Type' => 'text/html']);
    });

    queuedOriginCacheHit($row->url);
    Queue::assertPushed(RefreshOriginStaleCachedUrlJob::class, 1);
    $job = queuedOriginRefreshJob();
    $job->handle();
    $row->refresh();

    if (in_array($state, ['active claim', 'retry backoff'], true)) {
        expect($renders)->toBe(0)->and($row->attempts)->toBe(1)
            ->and(Storage::disk('page_cache')->get(queuedOriginCachePath($row)))->toBe('Previous public HTML');
    } elseif ($state === 'reclaimed during render') {
        expect($renders)->toBe(1)->and($row->claim_token)->toBe('new-owner')
            ->and($row->status)->toBe(StaleCachedUrl::STATUS_PROCESSING)
            ->and(Storage::disk('page_cache')->get(queuedOriginCachePath($row)))->toBe('Previous public HTML');
    } elseif ($state === 'invalidated during render') {
        expect($renders)->toBe(1)->and($row->status)->toBe(StaleCachedUrl::STATUS_FAILED)
            ->and(Storage::disk('page_cache')->exists(queuedOriginCachePath($row)))->toBeFalse();
    } else {
        expect($renders)->toBe(1)->and($row->status)->toBe(StaleCachedUrl::STATUS_PROCESSED)
            ->and($row->claim_token)->toBeNull()->and($row->attempts)->toBe(0)
            ->and(Storage::disk('page_cache')->get(queuedOriginCachePath($row)))->toBe('Queued fresh HTML');
    }
})->with(['active claim', 'expired claim', 'retry backoff', 'retry ready', 'reclaimed during render', 'invalidated during render']);

it('records exhausted refresh attempts without logging the raw URL', function (): void {
    $log = Log::spy();
    $url = 'https://example.test/private-path?token=secret';

    (new RefreshOriginStaleCachedUrlJob($url))->failed(new RuntimeException('render failed'));

    $log->shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context): bool => $context === [
            'url_hash' => CachedModelUrl::hashUrl($url),
            'exception' => RuntimeException::class,
        ],
    );
});
