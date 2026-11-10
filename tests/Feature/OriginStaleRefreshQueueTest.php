<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\Core\Models\SiteDomain;
use Capell\HtmlCache\Actions\ProcessStaleHtmlCacheAction;
use Capell\HtmlCache\Data\HtmlCacheEligibilityReportData;
use Capell\HtmlCache\Data\HtmlCacheOriginDecisionData;
use Capell\HtmlCache\Enums\HtmlCacheEligibilityReason;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Jobs\FlushHtmlCacheHitBatchJob;
use Capell\HtmlCache\Jobs\RefreshOriginStaleCachedUrlJob;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Models\StaleCachedUrl;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Capell\HtmlCache\Support\Cache\HtmlCachePublicationGuard;
use Capell\HtmlCache\Support\Cache\HtmlCacheStore;
use Capell\HtmlCache\Support\Cache\OriginRefreshLock;
use Capell\HtmlCache\Support\Cache\PageCache;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Illuminate\Bus\UniqueLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\CacheLock;
use Illuminate\Cache\Lock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Filesystem\Filesystem;
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

require_once __DIR__ . '/../Support/CachedModelUrlsTestSupport.php';

uses(HtmlCacheTestCase::class);

/** Simulates the store losing every record of the origin refresh lock, as eviction or a restart would. */
function evictOriginRefreshLock(): void
{
    Cache::lock(OriginRefreshLock::KEY)->forceRelease();

    foreach (range(1, resolve(OriginRefreshLock::class)->slotCount()) as $slot) {
        Cache::lock(OriginRefreshLock::KEY . ':slot:' . $slot)->forceRelease();
    }

    Cache::lock('capell-html-cache:origin-refresh:gate')->forceRelease();
    Cache::lock('capell-html-cache:origin-refresh:refresher')->forceRelease();
}

function queuedOriginRoute(string $uri, callable $origin): Illuminate\Routing\Route
{
    return Route::get($uri, $origin)->middleware(HtmlCacheMiddleware::class);
}

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

afterEach(function (): void {
    OriginRefreshLock::reset();
});

beforeEach(function (): void {
    // The hard cap runs on a monotonic clock; tests drive it from application time.
    OriginRefreshLock::reset(fn (): int => now()->getTimestamp() * 1_000_000_000);
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
    queuedOriginRoute('/stale', function () use (&$renders): Response {
        $renders++;

        return response('Refreshed public HTML', 200, ['Content-Type' => 'text/html']);
    });
    $dispatcher = resolve(Dispatcher::class);
    Bus::swap(Mockery::mock($dispatcher));
    Bus::shouldReceive('dispatch')->with(Mockery::type(RefreshOriginStaleCachedUrlJob::class))
        ->andReturnUsing(static function (RefreshOriginStaleCachedUrlJob $job) use ($dispatcher): mixed {
            if (Fiber::getCurrent() instanceof Fiber) {
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
    queuedOriginRoute('/stale', static fn (): Response => response('Inline render', 200, ['Content-Type' => 'text/html']));

    expect(queuedOriginCacheHit($row->url)->getContent())->toBe('Previous public HTML');
    app()->terminate();

    expect($row->refresh()->status)->toBe(StaleCachedUrl::STATUS_PENDING)
        ->and(Storage::disk('page_cache')->get(queuedOriginCachePath($row)))->toBe('Previous public HTML');
    Queue::assertNotPushed(RefreshOriginStaleCachedUrlJob::class);
})->with(['sync', 'deferred', 'background', 'failover']);

it('retires a stale page whose refreshed 404 the cache declines to write', function (string $cause): void {
    $row = queuedOriginStaleRow();
    $disk = Storage::disk('page_cache');
    $cachePath = queuedOriginCachePath($row);
    $errorCachePath = substr($cachePath, 0, -strlen('.html')) . PageCache::ERROR_EXTENSION;
    $row->forceFill(['error_cache_path' => $errorCachePath])->save();
    $renders = 0;
    queuedOriginRoute('/stale', function () use (&$renders): Response {
        $renders++;

        return response('Fresh missing page', Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/html']);
    });

    expect(queuedOriginCacheHit($row->url)->getStatusCode())->toBe(Response::HTTP_OK);
    Queue::assertPushed(RefreshOriginStaleCachedUrlJob::class, 1);
    $job = queuedOriginRefreshJob();

    if ($cause === 'error page cap of zero') {
        config()->set('capell-html-cache.error_pages.max_files_per_host', 0);
    } else {
        // The atomic write of the error page throws inside the publication lock.
        app()->instance(Filesystem::class, new class extends Filesystem
        {
            #[Override]
            public function put($path, $contents, $lock = false): int|bool
            {
                if (str_contains((string) $path, PageCache::ERROR_EXTENSION . '.tmp.')) {
                    throw new RuntimeException('Simulated disk failure.');
                }

                return parent::put($path, $contents, $lock);
            }
        });
    }

    $job->handle();
    resolve(UniqueLock::class)->release($job);

    // One render: a declined write is not a moved generation, so no second attempt.
    expect($renders)->toBe(1)
        ->and($row->refresh()->status)->toBe(StaleCachedUrl::STATUS_NOT_APPLICABLE)
        ->and($row->claim_token)->toBeNull()
        ->and($row->last_error)->toContain('Retired the cached 404')
        ->and($disk->exists($cachePath))->toBeFalse();

    // Replayed jobs and the scheduled processor leave the resolved row alone.
    $job->handle();
    expect($renders)->toBe(1)
        ->and(ProcessStaleHtmlCacheAction::run(1)->attempted)->toBe(0);
})->with(['error page cap of zero', 'write failure']);

it('renders a refreshed 200 again when its cache write throws and reports a failed write truthfully', function (int $failures): void {
    $row = queuedOriginStaleRow();
    $disk = Storage::disk('page_cache');
    $cachePath = queuedOriginCachePath($row);
    $renders = 0;
    queuedOriginRoute('/stale', function () use (&$renders): Response {
        $renders++;

        return response('Refreshed public HTML', Response::HTTP_OK, ['Content-Type' => 'text/html']);
    });
    expect(queuedOriginCacheHit($row->url)->getStatusCode())->toBe(Response::HTTP_OK);
    $job = queuedOriginRefreshJob();
    // The atomic write throws inside the publication lock for the first N renders; the
    // publication generation never moves, so this is not a generation rejection.
    app()->instance(Filesystem::class, new class($failures) extends Filesystem
    {
        public function __construct(private int $remainingFailures) {}

        #[Override]
        public function put($path, $contents, $lock = false): int|bool
        {
            if ($this->remainingFailures > 0 && str_contains((string) $path, '.html.tmp.')) {
                $this->remainingFailures--;

                throw new RuntimeException('Simulated disk failure.');
            }

            return parent::put($path, $contents, $lock);
        }
    });

    if ($failures === 1) {
        $job->handle();

        expect($renders)->toBe(2)
            ->and($row->refresh()->status)->toBe(StaleCachedUrl::STATUS_PROCESSED)
            ->and($disk->get($cachePath))->toBe('Refreshed public HTML');

        return;
    }

    $message = null;

    try {
        $job->handle();
    } catch (RuntimeException $exception) {
        $message = $exception->getMessage();
    }

    expect($renders)->toBe(2)
        ->and($message)->toContain('cache write was refused or failed')
        ->and($message)->not->toContain('generation advanced')
        ->and($row->refresh()->status)->toBe(StaleCachedUrl::STATUS_FAILED)
        ->and($disk->get($cachePath))->toBe('Previous public HTML');
})->with(['throws once' => 1, 'throws on both renders' => 2]);

it('retires a stale page whose refreshed 404 is declined before publication with no recorded reason', function (): void {
    $row = queuedOriginStaleRow();
    $disk = Storage::disk('page_cache');
    $cachePath = queuedOriginCachePath($row);
    $row->forceFill(['error_cache_path' => substr($cachePath, 0, -strlen('.html')) . PageCache::ERROR_EXTENSION])->save();
    expect(queuedOriginCacheHit($row->url)->getStatusCode())->toBe(Response::HTTP_OK);
    $job = queuedOriginRefreshJob();
    $kernel = Mockery::mock(Kernel::class);
    $kernel->shouldReceive('handle')->once()->andReturnUsing(function (Request $request): Response {
        // The middleware's state when cacheResponse() declines before publishing (for
        // example fragments without cache data): an eligible response, an empty decision
        // and no publication-guard rejection.
        $request->attributes->set(HtmlCacheMiddleware::ORIGIN_DECISION_ATTRIBUTE, new HtmlCacheOriginDecisionData);
        $request->attributes->set(HtmlCacheMiddleware::ELIGIBILITY_REPORT_ATTRIBUTE, new HtmlCacheEligibilityReportData(url: $request->fullUrl(), eligible: true, reasons: []));

        return response('Fresh missing page', Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/html']);
    });
    $kernel->shouldReceive('terminate')->once();
    app()->instance(Kernel::class, $kernel);

    $job->handle();

    expect($row->refresh()->status)->toBe(StaleCachedUrl::STATUS_NOT_APPLICABLE)
        ->and($disk->exists($cachePath))->toBeFalse();
});

it('does not evict a cached 404 when a newer claim takes the row before the files are removed', function (): void {
    $row = queuedOriginStaleRow();
    $disk = Storage::disk('page_cache');
    $cachePath = queuedOriginCachePath($row);
    $row->forceFill(['error_cache_path' => substr($cachePath, 0, -strlen('.html')) . PageCache::ERROR_EXTENSION])->save();
    queuedOriginRoute('/stale', static fn (): Response => response('Fresh missing page', Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/html']));
    expect(queuedOriginCacheHit($row->url)->getStatusCode())->toBe(Response::HTTP_OK);
    $job = queuedOriginRefreshJob();
    config()->set('capell-html-cache.error_pages.max_files_per_host', 0);
    // The post-render claim check passes; a newer owner then reclaims the row before
    // the eviction takes its lock.
    $reclaimed = false;
    DB::listen(function (QueryExecuted $query) use ($row, &$reclaimed): void {
        if ($reclaimed || ! str_contains($query->sql, 'stale_cached_urls')) {
            return;
        }

        // Fire right after the action's own post-render currency check has passed.
        $afterCurrencyCheck = array_any(
            debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS),
            static fn (array $frame): bool => $frame['function'] === 'assertStaleCachedUrlClaimIsCurrent',
        );

        if (! $afterCurrencyCheck) {
            return;
        }

        $reclaimed = true;
        DB::table('stale_cached_urls')->where('id', $row->getKey())->update(['claim_token' => 'newer-owner']);
    });

    expect(fn () => $job->handle())->toThrow(RuntimeException::class, 'no longer current');

    expect($reclaimed)->toBeTrue()
        ->and($row->refresh()->claim_token)->toBe('newer-owner')
        ->and($disk->get($cachePath))->toBe('Previous public HTML');
});

it('keeps rejected origin writes retryable when terminal 404 resolution is unsafe', function (string $condition): void {
    $row = queuedOriginStaleRow();
    $errorCachePath = substr(queuedOriginCachePath($row), 0, -strlen('.html')) . PageCache::ERROR_EXTENSION;
    $row->forceFill(['error_cache_path' => $errorCachePath])->save();
    Storage::disk('page_cache')->put($errorCachePath, 'Previous missing page');
    $renders = 0;
    $kernel = Mockery::mock(Kernel::class);
    $kernel->shouldReceive('handle')->times($condition === 'publication rejected' ? 2 : 1)
        ->andReturnUsing(function (Request $request) use ($row, $condition, &$renders): Response {
            $renders++;
            $request->attributes->set(HtmlCacheMiddleware::ORIGIN_DECISION_ATTRIBUTE, new HtmlCacheOriginDecisionData(
                rejectionReason: $condition === 'recorded rejection' ? HtmlCacheEligibilityReason::UnsafePublicOutput : null,
            ));
            $ineligible = $condition === 'ineligible report';
            $request->attributes->set(HtmlCacheMiddleware::ELIGIBILITY_REPORT_ATTRIBUTE, new HtmlCacheEligibilityReportData(
                url: $request->fullUrl(),
                eligible: ! $ineligible,
                reasons: $ineligible ? [HtmlCacheEligibilityReason::ResponseNoStore] : [],
            ));

            if ($condition === 'reclaimed claim') {
                StaleCachedUrl::query()->whereKey($row->getKey())->update(['claim_token' => 'new-owner']);
            } elseif ($condition === 'publication rejected') {
                $request->attributes->set(HtmlCachePublicationGuard::REJECTED_ATTRIBUTE, true);
            } elseif ($condition === 'fragment failed') {
                $request->attributes->set(HtmlCacheMiddleware::FRAGMENT_RENDER_FAILED_ATTRIBUTE, true);
            }

            return response('Rejected origin HTML', $condition === 'successful status' ? Response::HTTP_OK : Response::HTTP_NOT_FOUND, [
                'Content-Type' => 'text/html',
                'Cache-Control' => 'public, max-age=60',
            ]);
        });
    $kernel->shouldReceive('terminate')->times($condition === 'publication rejected' ? 2 : 1);
    app()->instance(Kernel::class, $kernel);
    config()->set('capell-html-cache.write_enabled', $condition !== 'writes disabled');
    config()->set('capell-html-cache.enabled', $condition !== 'cache disabled');

    expect(fn () => new RefreshOriginStaleCachedUrlJob($row->url)->handle())->toThrow(RuntimeException::class);

    expect($renders)->toBe($condition === 'publication rejected' ? 2 : 1)
        ->and($row->fresh())->not->toBeNull()
        ->and($row->refresh()->status)->toBe($condition === 'reclaimed claim' ? StaleCachedUrl::STATUS_PROCESSING : StaleCachedUrl::STATUS_FAILED)
        ->and($row->claim_token)->toBe($condition === 'reclaimed claim' ? 'new-owner' : null)
        ->and(Storage::disk('page_cache')->get(queuedOriginCachePath($row)))->toBe('Previous public HTML')
        ->and(Storage::disk('page_cache')->get($errorCachePath))->toBe('Previous missing page');
})->with(['successful status', 'writes disabled', 'cache disabled', 'fragment failed', 'recorded rejection', 'ineligible report', 'reclaimed claim', 'publication rejected']);

it('preserves stale serving and durable retry when the queue broker fails', function (bool $recordHits): void {
    $row = queuedOriginStaleRow();
    config()->set('capell-html-cache.hit_recording.enabled', $recordHits);
    $renders = 0;
    queuedOriginRoute('/stale', function () use (&$renders): Response {
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
    expect(ProcessStaleHtmlCacheAction::run(1, suppressInlineEdgePurge: true)->attempted)->toBe(1)
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
    queuedOriginRoute('/stale', function () use ($row, $state, &$renders): Response {
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

    if (in_array($state, ['reclaimed during render', 'invalidated during render'], true)) {
        expect(fn () => $job->handle())->toThrow(RuntimeException::class);
    } else {
        $job->handle();
    }

    $row->refresh();

    if (in_array($state, ['active claim', 'retry backoff'], true)) {
        expect($renders)->toBe(0)->and($row->attempts)->toBe(1)
            ->and(Storage::disk('page_cache')->get(queuedOriginCachePath($row)))->toBe('Previous public HTML');
    } elseif ($state === 'reclaimed during render') {
        expect($renders)->toBe(1)->and($row->claim_token)->toBe('new-owner')
            ->and($row->status)->toBe(StaleCachedUrl::STATUS_PROCESSING)
            ->and(Storage::disk('page_cache')->get(queuedOriginCachePath($row)))->toBe('Previous public HTML');
    } elseif ($state === 'invalidated during render') {
        // A guard rejection earns one retry under a fresh token; a second rejection still fails the row.
        expect($renders)->toBe(2)->and($row->status)->toBe(StaleCachedUrl::STATUS_FAILED)
            ->and($row->last_error)->toContain('the publication guard rejected the render twice')
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

    new RefreshOriginStaleCachedUrlJob($url)->failed(new RuntimeException('render failed'));

    $log->shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context): bool => $context === [
            'url_hash' => CachedModelUrl::hashUrl($url),
            'exception' => RuntimeException::class,
        ],
    );
});

it('admits a refresh job through the lock alone, so a deployment cannot acquire while the job renders', function (): void {
    $row = queuedOriginStaleRow();
    $lock = resolve(OriginRefreshLock::class);
    $acquiredMidRender = null;
    queuedOriginRoute('/stale', function () use ($lock, &$acquiredMidRender): Response {
        // The barrier: the deployment tries to take the lock while the job is rendering.
        $acquiredMidRender = $lock->tryAcquireExclusive('deploy', 120, 1200);

        return response('Refreshed public HTML', 200, ['Content-Type' => 'text/html']);
    });
    $job = new RefreshOriginStaleCachedUrlJob($row->url);
    $job->withFakeQueueInteractions();

    $job->handle();

    $job->assertNotReleased();
    expect($acquiredMidRender)->toBeFalse()
        ->and($row->refresh()->status)->toBe(StaleCachedUrl::STATUS_PROCESSED)
        ->and($lock->isExclusiveHeld())->toBeFalse()
        ->and($lock->acquireExclusive('deploy', 120, 0, 1200))->toBeTrue();
});

it('defers a job queued before the deployment took the lock, without rendering', function (): void {
    $row = queuedOriginStaleRow();
    $renders = 0;
    queuedOriginRoute('/stale', function () use (&$renders): Response {
        $renders++;

        return response('Refreshed public HTML', 200, ['Content-Type' => 'text/html']);
    });
    queuedOriginCacheHit($row->url);
    $queued = queuedOriginRefreshJob();
    $lock = resolve(OriginRefreshLock::class);
    expect($lock->acquireExclusive('deploy', 120, 0, 1200))->toBeTrue();

    $queued->withFakeQueueInteractions();
    $queued->handle();

    // A deployment refresher holds the lock for minutes, so the job polls less often than it would behind an ordinary render.
    $queued->assertReleased(30);
    expect($renders)->toBe(0)
        ->and($row->refresh()->status)->toBe(StaleCachedUrl::STATUS_PENDING)
        ->and(Storage::disk('page_cache')->get(queuedOriginCachePath($row)))->toBe('Previous public HTML');

    $lock->release('deploy');
    $retry = new RefreshOriginStaleCachedUrlJob($row->url);
    $retry->withFakeQueueInteractions();
    $retry->handle();

    $retry->assertNotReleased();
    expect($renders)->toBe(1)
        ->and($row->refresh()->status)->toBe(StaleCachedUrl::STATUS_PROCESSED);
});

it('skips the cheap visitor dispatch while a refresher holds the lock and queues again once it is released', function (): void {
    $row = queuedOriginStaleRow();
    queuedOriginRoute('/stale', static fn (): Response => response('Refreshed public HTML', 200, ['Content-Type' => 'text/html']));
    $lock = resolve(OriginRefreshLock::class);
    expect($lock->acquireExclusive('deploy', 120, 0, 1200))->toBeTrue();

    expect(queuedOriginCacheHit($row->url)->getContent())->toBe('Previous public HTML');
    Queue::assertNotPushed(RefreshOriginStaleCachedUrlJob::class);

    $lock->release('deploy');
    queuedOriginCacheHit($row->url);

    Queue::assertPushed(RefreshOriginStaleCachedUrlJob::class, 1);
});

it('refuses renewal past the absolute deadline and then gives the lock back', function (): void {
    $lock = resolve(OriginRefreshLock::class);
    expect($lock->acquireExclusive('deploy', 120, 0, 600))->toBeTrue();

    for ($elapsed = 100; $elapsed < 600; $elapsed += 100) {
        $this->travel(100)->seconds();
        expect($lock->renew('deploy', 120))->toBeTrue()
            ->and($lock->isExclusiveHeld())->toBeTrue();
    }

    $this->travel(101)->seconds();

    expect($lock->renew('deploy', 120))->toBeFalse()
        ->and($lock->isExclusiveHeld())->toBeFalse()
        ->and($lock->tryAcquireSlot('another', 60))->toBeTrue();
});

it('lets an abandoned holder lapse on its short TTL and only the holder renew or release', function (): void {
    $lock = resolve(OriginRefreshLock::class);
    expect($lock->acquireExclusive('crashed-job', 120, 0))->toBeTrue()
        ->and($lock->tryAcquireSlot('intruder', 60))->toBeFalse()
        ->and($lock->renew('intruder', 60))->toBeFalse()
        ->and($lock->release('intruder'))->toBeFalse()
        ->and($lock->isExclusiveHeld())->toBeTrue();

    $this->travel(121)->seconds();

    // Admission is what matters: some drivers keep reporting an expired record until the next acquisition.
    expect($lock->tryAcquireSlot('next', 60))->toBeTrue();
});

it('rejects a lock without an owner or without a TTL or hard cap', function (string $owner, int $ttl, ?int $cap): void {
    resolve(OriginRefreshLock::class)->tryAcquireExclusive($owner, $ttl, $cap);
})->with([
    'no owner' => [' ', 60, null],
    'no ttl' => ['deploy', 0, null],
    'no cap' => ['deploy', 60, 0],
])->throws(InvalidArgumentException::class);

it('lets a holder whose lock lapsed release without deleting the replacement owner', function (): void {
    $lock = resolve(OriginRefreshLock::class);
    expect($lock->tryAcquireExclusive('first', 60))->toBeTrue();

    // The first owner's records lapse or are evicted; a second owner is admitted.
    evictOriginRefreshLock();
    expect($lock->tryAcquireExclusive('second', 60))->toBeTrue();

    expect($lock->release('first'))->toBeFalse()
        ->and($lock->isExclusiveHeld())->toBeTrue()
        ->and($lock->tryAcquireSlot('third', 60))->toBeFalse()
        ->and($lock->release('second'))->toBeTrue()
        ->and($lock->isExclusiveHeld())->toBeFalse();
});

it('accepts a hard cap shorter than the renewal TTL and never holds past it', function (): void {
    $lock = resolve(OriginRefreshLock::class);

    expect($lock->tryAcquireExclusive('deploy', 120, 10))->toBeTrue()
        ->and($lock->isExclusiveHeld())->toBeTrue();

    $this->travel(11)->seconds();

    expect($lock->renewOwned())->toBeFalse()
        ->and($lock->tryAcquireSlot('next', 60))->toBeTrue();
});

it('reports a lost lock from renewOwned so a refresher stops rendering', function (): void {
    $lock = resolve(OriginRefreshLock::class);

    expect($lock->renewOwned())->toBeNull()
        ->and($lock->tryAcquireExclusive('deploy', 120, 600))->toBeTrue()
        ->and($lock->renewOwned())->toBeTrue();

    evictOriginRefreshLock();
    expect($lock->tryAcquireSlot('job', 60))->toBeTrue();

    expect($lock->renewOwned())->toBeFalse()
        ->and($lock->renewOwned())->toBeNull();
});

it('measures the hard cap on the monotonic clock, not the application clock', function (): void {
    $monotonic = 0;
    OriginRefreshLock::reset(function () use (&$monotonic): int {
        return $monotonic;
    });
    $lock = resolve(OriginRefreshLock::class);

    expect($lock->tryAcquireExclusive('deploy', 120, 100))->toBeTrue();

    // The application clock moves backwards; real elapsed time keeps counting.
    $this->travel(-60)->seconds();
    $monotonic = 101 * 1_000_000_000;

    expect($lock->renew('deploy', 120))->toBeFalse();
});

it('still queues a visitor refresh while an ordinary job holds the lock', function (): void {
    $row = queuedOriginStaleRow();
    queuedOriginRoute('/stale', static fn (): Response => response('Refreshed public HTML', 200, ['Content-Type' => 'text/html']));
    $lock = resolve(OriginRefreshLock::class);
    expect($lock->tryAcquireSlot('job:other-url', 120))->toBeTrue();

    queuedOriginCacheHit($row->url);

    // That job refreshes one other URL; this one still needs its own job, which defers until the lock frees.
    Queue::assertPushed(RefreshOriginStaleCachedUrlJob::class);
    expect($lock->isRefresherHeld())->toBeFalse();
});

it('keeps a deferred refresh queued for longer than a deployment refresher can hold the lock', function (): void {
    config()->set('capell-html-cache.origin_stale_while_revalidate.refresher_hard_cap_seconds', 1200);

    $job = new RefreshOriginStaleCachedUrlJob('https://example.test/stale');

    expect($job->retryUntil()->getTimestamp())->toBeGreaterThan(now()->addSeconds(1200)->getTimestamp());
});

it('does not render from the process-stale action while another holder owns the lock, and renders once it is free', function (): void {
    $row = queuedOriginStaleRow();
    queuedOriginRoute('/stale', static fn (): Response => response('Refreshed public HTML', 200, ['Content-Type' => 'text/html']));
    $lock = resolve(OriginRefreshLock::class);
    // Another process holds the lock exclusively: this one has no lease of its own.
    Cache::lock('capell-html-cache:origin-refresh:gate', 120, 'deploy-elsewhere')->get();

    $blocked = ProcessStaleHtmlCacheAction::run(1);

    expect($blocked->attempted)->toBe(0)
        ->and($blocked->deferred)->toBe(1)
        ->and($row->refresh()->status)->toBe(StaleCachedUrl::STATUS_PENDING);

    Cache::lock('capell-html-cache:origin-refresh:gate')->forceRelease();

    expect(ProcessStaleHtmlCacheAction::run(1)->attempted)->toBe(1)
        ->and($lock->tryAcquireSlot('next', 60))->toBeTrue();
});

it('retries a lost refresher lease only under fresh admission, so the retry cannot render alongside others', function (): void {
    $row = queuedOriginStaleRow();
    queuedOriginRoute('/stale', static fn (): Response => response('Refreshed public HTML', 200, ['Content-Type' => 'text/html']));
    $lock = resolve(OriginRefreshLock::class);
    expect($lock->tryAcquireExclusive('deploy', 120, 600))->toBeTrue();

    evictOriginRefreshLock();
    expect($lock->tryAcquireSlot('job:rendering', 120))->toBeTrue();
    foreach (range(2, $lock->slotCount()) as $n) {
        expect($lock->tryAcquireSlot('job:rendering-' . $n, 120))->toBeTrue();
    }

    expect(fn () => ProcessStaleHtmlCacheAction::make()->asJob(1))->toThrow(RuntimeException::class);

    // The retry has no lease of its own: it asks for admission and is refused while the job renders.
    expect(ProcessStaleHtmlCacheAction::run(1)->attempted)->toBe(0)
        ->and($row->refresh()->status)->toBe(StaleCachedUrl::STATUS_PENDING);
});

it('keeps a refresh job inside its lock lifetime and its uniqueness as long as it can wait', function (): void {
    config()->set('capell-html-cache.origin_stale_while_revalidate.refresher_hard_cap_seconds', 1200);
    $job = new RefreshOriginStaleCachedUrlJob('https://example.test/stale');

    expect($job->timeout)->toBeLessThan(120)
        ->and($job->uniqueFor())->toBeGreaterThanOrEqual(1200 + 300);
});

it('backs off for longer while a deployment refresher holds the lock', function (): void {
    $lock = resolve(OriginRefreshLock::class);
    $others = [];
    $ordinary = new RefreshOriginStaleCachedUrlJob('https://example.test/stale');
    $ordinary->withFakeQueueInteractions();
    $refreshing = new RefreshOriginStaleCachedUrlJob('https://example.test/stale');
    $refreshing->withFakeQueueInteractions();

    // Every slot is rendering: the job defers behind them, not behind a deployment.
    foreach (range(1, $lock->slotCount()) as $n) {
        expect($lock->tryAcquireSlot('job:other-' . $n, 120))->toBeTrue();
        $others[] = 'job:other-' . $n;
    }
    $ordinary->handle();
    array_map($lock->release(...), $others);

    expect($lock->tryAcquireExclusive('deploy', 120, 1200))->toBeTrue();
    $refreshing->handle();

    $ordinary->assertReleased(5);
    $refreshing->assertReleased(30);
});

it('holds a capped lock for its whole cap on a store whose locks cannot be refreshed', function (): void {
    Cache::extend('norefresh', fn () => Cache::repository(new class extends ArrayStore
    {
        public function lock($name, $seconds = 0, $owner = null): Lock
        {
            return new CacheLock($this, $name, $seconds, $owner);
        }
    }));
    config()->set('cache.stores.norefresh', ['driver' => 'norefresh']);
    config()->set('cache.default', 'norefresh');
    $lock = resolve(OriginRefreshLock::class);

    expect($lock->tryAcquireExclusive('deploy', 60, 600))->toBeTrue();

    // The store cannot extend a lock, so it was taken for the whole cap and renewal only confirms ownership.
    $this->travel(300)->seconds();

    expect($lock->tryAcquireSlot('intruder', 60))->toBeFalse()
        ->and($lock->renew('deploy', 20))->toBeTrue()
        ->and($lock->release('deploy'))->toBeTrue();
});

it('lets concurrent ordinary refreshes render side by side up to the slot count and frees a slot on release', function (): void {
    config()->set('capell-html-cache.origin_stale_while_revalidate.refresh_slots', 3);
    $lock = resolve(OriginRefreshLock::class);

    expect($lock->tryAcquireSlot('job:site-a', 120))->toBeTrue()
        ->and($lock->tryAcquireSlot('job:site-b', 120))->toBeTrue()
        ->and($lock->tryAcquireSlot('job:site-c', 120))->toBeTrue()
        ->and($lock->tryAcquireSlot('job:site-d', 120))->toBeFalse()
        ->and($lock->release('job:site-b'))->toBeTrue()
        ->and($lock->tryAcquireSlot('job:site-d', 120))->toBeTrue();
});

it('runs two queued refresh jobs for different urls concurrently', function (): void {
    $b = new RefreshOriginStaleCachedUrlJob('https://example.test/b');
    $b->withFakeQueueInteractions();
    $lock = resolve(OriginRefreshLock::class);

    // The first job is mid-render (it holds its slot) while the second is picked up.
    expect($lock->tryAcquireSlot('job:in-flight', 120))->toBeTrue();
    $b->handle();

    $b->assertNotReleased();
    expect($lock->release('job:in-flight'))->toBeTrue();
});

it('gives the whole lock to exclusive work: it waits for nothing when idle, refuses new slots while it runs, and frees them after', function (): void {
    $lock = resolve(OriginRefreshLock::class);

    $seen = $lock->exclusive('expiry:test', 60, 0, null, fn (): array => [
        $lock->isExclusiveHeld(),
        $lock->tryAcquireSlot('job:late', 60),
        $lock->isRefresherHeld(),
    ]);

    expect($seen)->toBe([true, false, false])
        ->and($lock->isExclusiveHeld())->toBeFalse()
        ->and($lock->tryAcquireSlot('job:after', 60))->toBeTrue();
});

it('cannot take the whole lock while an ordinary slot is rendering, and rolls back so jobs are not left deferred', function (): void {
    $lock = resolve(OriginRefreshLock::class);
    expect($lock->tryAcquireSlot('job:rendering', 120))->toBeTrue();

    expect($lock->tryAcquireExclusive('expiry:test', 60))->toBeFalse()
        ->and($lock->isExclusiveHeld())->toBeFalse()
        ->and($lock->tryAcquireSlot('job:next', 60))->toBeTrue();
});

it('forgets a capped lease when the scoped call ends, even when the work throws', function (): void {
    $lock = resolve(OriginRefreshLock::class);

    expect(fn () => $lock->exclusive('deploy', 120, 0, 1200, function (): never {
        throw new RuntimeException('render failed');
    }))->toThrow(RuntimeException::class);

    expect($lock->renewOwned())->toBeNull()
        ->and($lock->isExclusiveHeld())->toBeFalse()
        ->and($lock->tryAcquireSlot('job:next', 60))->toBeTrue();
});

it('rejects a capped lease whose TTL a single slow render could outlast', function (): void {
    expect(fn () => resolve(OriginRefreshLock::class)->tryAcquireExclusive('deploy', 20, 1200))->toThrow(InvalidArgumentException::class);
});

/** A store whose every lock lookup lets a callback move time, as a long wait would. */
function tickingLockStore(Closure $tick): void
{
    Cache::extend('ticking', fn () => Cache::repository(new class($tick) extends ArrayStore
    {
        public function __construct(private readonly Closure $tick)
        {
            parent::__construct();
        }

        public function lock($name, $seconds = 0, $owner = null): Lock
        {
            ($this->tick)();

            /** @var Lock $lock */
            $lock = parent::lock($name, $seconds, $owner);

            return $lock;
        }
    }));
    config()->set('cache.stores.ticking', ['driver' => 'ticking']);
    config()->set('cache.default', 'ticking');
}

it('keeps the gate and its slots alive while exclusive work waits for a long render, so it is not starved', function (): void {
    $state = new stdClass;
    $state->busy = false;
    $state->armed = false;
    $state->until = 0;
    $state->stolen = false;
    $state->ticks = 0;
    tickingLockStore(function () use ($state): void {
        if ($state->busy) {
            return;
        }

        $state->busy = true;
        $state->ticks++;
        $this->travel(3)->seconds();
        // Once the waiting work holds the gate, a lapse would let anyone take it; a heartbeat keeps refreshing it.
        /** @var Lock $gate */
        $gate = Cache::lock(OriginRefreshLock::KEY);
        $state->armed = $state->armed || $gate->isOwnedBy('expiry:test');

        // Only while the wait is still running: giving up releases the gate on purpose.
        $state->stolen = $state->stolen || ($state->armed && hrtime(true) < $state->until && Cache::lock(OriginRefreshLock::KEY, 60, 'thief')->get());
        $state->busy = false;
    });
    $lock = resolve(OriginRefreshLock::class);
    $state->busy = true;
    expect($lock->tryAcquireSlot('job:long-render', 6000))->toBeTrue();
    $state->busy = false;
    $state->until = hrtime(true) + 3_600_000_000;

    expect($lock->acquireExclusive('expiry:test', 60, 4))->toBeFalse()
        ->and($state->ticks)->toBeGreaterThan(5)
        ->and($state->stolen)->toBeFalse();
});

it('is blocked by an abandoned admission for no longer than the lock wait the expiry sweep allows', function (): void {
    $state = new stdClass;
    $state->busy = false;
    tickingLockStore(function () use ($state): void {
        if (! $state->busy) {
            $state->busy = true;
            $this->travel(4)->seconds();
            $state->busy = false;
        }
    });
    $lock = resolve(OriginRefreshLock::class);
    $state->busy = true;
    // A process-stale run killed mid-render: its admission is never released.
    expect($lock->tryAcquireSlot('process:abandoned', OriginRefreshLock::MAX_SLOT_TTL_SECONDS))->toBeTrue();
    $state->busy = false;

    expect($lock->acquireExclusive('expiry:test', 60, 5))->toBeTrue()
        ->and($lock->isExclusiveHeld())->toBeTrue();
});

it('stops waiting as soon as a lease it holds is lost, rather than counting what it thinks it owns', function (): void {
    $ticks = 0;
    tickingLockStore(function () use (&$ticks): void {
        // The gate disappears from the store partway through the wait.
        if (++$ticks === 12) {
            Cache::lock(OriginRefreshLock::KEY)->forceRelease();
        }
    });
    $lock = resolve(OriginRefreshLock::class);
    expect($lock->tryAcquireSlot('job:render', 6000))->toBeTrue();

    $started = hrtime(true);

    expect($lock->acquireExclusive('expiry:test', 60, 20))->toBeFalse()
        ->and((hrtime(true) - $started) / 1_000_000_000)->toBeLessThan(10);
});
