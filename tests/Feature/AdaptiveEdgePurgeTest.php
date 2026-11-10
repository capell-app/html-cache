<?php

declare(strict_types=1);

use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Capell\HtmlCache\Actions\DrainEdgeCachePurgeQueueAction;
use Capell\HtmlCache\Actions\InspectEdgeCachePurgeThrottleAction;
use Capell\HtmlCache\Actions\PurgeEdgeCacheAction;
use Capell\HtmlCache\Actions\ResolveEdgeCacheTagsAction;
use Capell\HtmlCache\Contracts\CachePurger;
use Capell\HtmlCache\Data\EdgeCachePurgeData;
use Capell\HtmlCache\Data\EdgePurgeResponseData;
use Capell\HtmlCache\Data\EdgePurgeThrottleSettingsData;
use Capell\HtmlCache\Enums\EdgePurgeOutcome;
use Capell\HtmlCache\Health\HtmlCacheHealthCheck;
use Capell\HtmlCache\Support\Cache\EdgePurge\EdgePurgePendingQueue;
use Capell\HtmlCache\Support\Cache\EdgePurge\EdgePurgeRateGovernor;
use Capell\HtmlCache\Support\Cache\Purgers\CloudflareCachePurger;
use Capell\HtmlCache\Support\Cache\Purgers\NullCachePurger;
use Capell\HtmlCache\Tests\Fixtures\FakeEdgePurgeProvider;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Lorisleiva\Actions\Decorators\JobDecorator;

uses(HtmlCacheTestCase::class);

const EDGE_ZONE = 'fake:zone';

beforeEach(function (): void {
    // A real queue: on the sync queue purges are deliberately sent directly.
    config()->set('queue.default', 'database');
    config()->set('capell-html-cache.purge.throttle', [
        'enabled' => true,
        'coalesce_window_seconds' => 2,
        'max_urls_per_request' => 30,
        'max_tags_per_request' => 30,
        'requests_per_second' => 2,
        'burst' => 10,
        'min_requests_per_second' => 0.05,
        'decrease_factor' => 0.5,
        'increase_step' => 0.25,
        'increase_after_successes' => 3,
        'learned_rate_ttl_seconds' => 3600,
        'default_retry_after_seconds' => 30,
        'max_retry_after_seconds' => 900,
        'failure_backoff_seconds' => 45,
        'max_batches_per_drain' => 50,
        'escalate_to_full_purge' => true,
        'escalate_pending_threshold' => 1000,
        'escalate_after_seconds' => 900,
    ]);
    useFileEdgePurgeStore();
    $this->freezeSecond();

    $this->provider = new FakeEdgePurgeProvider(EDGE_ZONE);
    app()->instance(CachePurger::class, $this->provider);
});

afterEach(function (): void {
    File::deleteDirectory(edgePurgeStoreDirectory());
});

function edgePurgeStoreDirectory(): string
{
    $path = config('cache.stores.edge_purge_file.path');

    return is_string($path) ? dirname($path) : throw new RuntimeException('The edge purge file store is not configured.');
}

/**
 * Run a purge the way a queue worker does and return the job.
 */
function runQueuedPurge(EdgeCachePurgeData $purge): JobDecorator
{
    $job = new JobDecorator(PurgeEdgeCacheAction::class, $purge);
    $job->withFakeQueueInteractions();
    $job->handle();

    return $job;
}

function pushedDrainToken(): string
{
    $queue = Queue::getFacadeRoot();

    if (! $queue instanceof QueueFake) {
        throw new RuntimeException('Fake the queue before reading pushed drain jobs.');
    }

    $job = $queue->pushed(JobDecorator::class, static fn (JobDecorator $job): bool => $job->decorates(DrainEdgeCachePurgeQueueAction::class))->last();
    $token = $job instanceof JobDecorator ? ($job->getParameters()[1] ?? null) : null;

    return is_string($token) ? $token : throw new RuntimeException('No drain job was pushed.');
}

/**
 * Queue a purge directly and return the drainer claim it took.
 */
function queueAndClaim(EdgePurgePendingQueue $queue, EdgeCachePurgeData $purge): string
{
    return $queue->add(EDGE_ZONE, $purge)->drainerToken ?? throw new RuntimeException('The purge did not claim the drainer.');
}

function throttleSettings(): EdgePurgeThrottleSettingsData
{
    return EdgePurgeThrottleSettingsData::fromConfig();
}

/**
 * Point the throttle at a file cache store, which survives a process and is
 * shared by forked workers, and return its directory.
 */
function useFileEdgePurgeStore(): string
{
    $directory = sys_get_temp_dir() . '/capell-edge-purge-' . bin2hex(random_bytes(6));
    mkdir($directory);
    config()->set('cache.stores.edge_purge_file', ['driver' => 'file', 'path' => $directory . '/cache', 'lock_path' => $directory . '/locks']);
    config()->set('capell-html-cache.purge.throttle.store', 'edge_purge_file');

    return $directory;
}

/** @return ArrayObject<int, MessageLogged> */
function captureEdgePurgeLogs(): ArrayObject
{
    /** @var ArrayObject<int, MessageLogged> $logs */
    $logs = new ArrayObject;
    Event::listen(MessageLogged::class, static function (MessageLogged $message) use ($logs): void {
        if (str_contains($message->message, 'edge purge')) {
            $logs[] = $message;
        }
    });

    return $logs;
}

it('coalesces a burst of queued purges into deduplicated provider-sized batches sent by one drain job', function (): void {
    Queue::fake();

    foreach (range(1, 75) as $page) {
        runQueuedPurge(new EdgeCachePurgeData(
            tags: ['page-' . $page % 40, 'site-1'],
            urls: ['https://example.test/page-' . $page, 'https://example.test/page-' . $page],
        ))->assertNotReleased();
    }

    DrainEdgeCachePurgeQueueAction::assertPushed(1);
    expect($this->provider->sent)->toBe([]);

    $result = DrainEdgeCachePurgeQueueAction::run(EDGE_ZONE, pushedDrainToken());

    $batchSizes = array_map(
        static fn (EdgeCachePurgeData $purge): int => count($purge->urls) + count($purge->tags),
        $this->provider->sent,
    );

    expect($result->finished())->toBeTrue()
        ->and($result->sentBatches)->toBe(5)
        ->and($batchSizes)->toBe([30, 30, 15, 30, 11])
        ->and($this->provider->sentUrls())->toHaveCount(75)->toBe(array_values(array_unique($this->provider->sentUrls())))
        ->and($this->provider->sentTags())->toHaveCount(41)
        ->and(collect($this->provider->sent)->every(static fn (EdgeCachePurgeData $purge): bool => $purge->urls === [] || $purge->tags === []))->toBeTrue()
        ->and(EdgePurgePendingQueue::fromSettings(throttleSettings())->status(EDGE_ZONE)->isEmpty())->toBeTrue();

    // The finished drain gave up its claim, so the next burst gets a new drainer.
    runQueuedPurge(new EdgeCachePurgeData(urls: ['https://example.test/later']));

    DrainEdgeCachePurgeQueueAction::assertPushed(2);
});

it('keeps a URL queued again while its batch was in flight', function (): void {
    $queue = EdgePurgePendingQueue::fromSettings(throttleSettings());
    $queue->add(EDGE_ZONE, new EdgeCachePurgeData(urls: ['https://example.test/a', 'https://example.test/b']));

    $batch = $queue->next(EDGE_ZONE) ?? throw new RuntimeException('Expected a pending batch.');
    $queue->add(EDGE_ZONE, new EdgeCachePurgeData(urls: ['https://example.test/a']));
    $queue->complete(EDGE_ZONE, $batch);

    expect($queue->next(EDGE_ZONE)?->purge()->urls)->toBe(['https://example.test/a']);
});

it('paces purges with one token bucket shared by concurrent workers', function (): void {
    config()->set('capell-html-cache.purge.throttle.burst', 3);
    config()->set('capell-html-cache.purge.throttle.requests_per_second', 1);

    // Two workers, each with its own governor, sharing only the cache store.
    $workerA = EdgePurgeRateGovernor::fromSettings(throttleSettings());
    $workerB = EdgePurgeRateGovernor::fromSettings(throttleSettings());

    $grants = 0;
    $waits = [];

    foreach (range(1, 4) as $round) {
        foreach ([$workerA, $workerB] as $worker) {
            $wait = $worker->reserve(EDGE_ZONE);
            $wait === 0.0 ? $grants++ : $waits[] = $wait;
        }
    }

    expect($grants)->toBe(3)
        ->and($waits)->toHaveCount(5)
        ->and(collect($waits)->min())->toBeGreaterThan(0.0)->toBeLessThanOrEqual(1.0);

    $this->travel(1)->seconds();

    expect($workerB->reserve(EDGE_ZONE))->toBe(0.0)
        ->and($workerA->reserve(EDGE_ZONE))->toBeGreaterThan(0.0);
});

it('never grants more than the burst to parallel worker processes', function (): void {
    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
        $this->fail('The parallel worker pacing test requires the pcntl and posix extensions.');
    }

    $directory = edgePurgeStoreDirectory();
    config()->set('capell-html-cache.purge.throttle.burst', 4);
    // Slow enough that no token refills while the workers run.
    config()->set('capell-html-cache.purge.throttle.requests_per_second', 0.001);
    config()->set('capell-html-cache.purge.throttle.min_requests_per_second', 0.001);
    $this->travelBack();

    $children = [];

    foreach (range(1, 4) as $worker) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('Cannot fork an edge purge worker.');
        }

        if ($pid === 0) {
            $governor = EdgePurgeRateGovernor::fromSettings(throttleSettings());
            $granted = 0;

            foreach (range(1, 5) as $attempt) {
                $granted += $governor->reserve(EDGE_ZONE) === 0.0 ? 1 : 0;
            }

            file_put_contents($directory . '/worker-' . $worker, (string) $granted);
            // Leave without running shutdown handlers that would close the parent's connections.
            posix_kill(posix_getpid(), SIGKILL);
        }

        $children[] = $pid;
    }

    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $granted = array_sum(array_map(
        static fn (int $worker): int => (int) file_get_contents($directory . '/worker-' . $worker),
        range(1, 4),
    ));

    expect($granted)->toBe(4);
});

it('halves the learned rate on a 429, honours Retry-After and recovers additively on success', function (): void {
    $governor = EdgePurgeRateGovernor::fromSettings(throttleSettings());

    $throttled = $governor->record(EDGE_ZONE, new EdgePurgeResponseData(EdgePurgeOutcome::Throttled, status: 429, retryAfterSeconds: 20));

    expect($throttled->requestsPerSecond)->toBe(1.0)
        ->and($throttled->blockedUntil)->toBe(now()->getTimestamp() + 20)
        ->and($governor->reserve(EDGE_ZONE))->toEqualWithDelta(20.0, 0.001);

    $governor->record(EDGE_ZONE, new EdgePurgeResponseData(EdgePurgeOutcome::Throttled, status: 429));
    expect($governor->state(EDGE_ZONE)->requestsPerSecond)->toBe(0.5)
        ->and($governor->state(EDGE_ZONE)->blockedUntil)->toBe(now()->getTimestamp() + 30);

    $this->travel(31)->seconds();
    expect($governor->reserve(EDGE_ZONE))->toBe(0.0);

    foreach (range(1, 3) as $success) {
        $rate = $governor->record(EDGE_ZONE, EdgePurgeResponseData::succeeded());
    }

    expect($rate->requestsPerSecond)->toBe(0.75);

    foreach (range(1, 30) as $success) {
        $rate = $governor->record(EDGE_ZONE, EdgePurgeResponseData::succeeded());
    }

    expect($rate->requestsPerSecond)->toBe(2.0);

    $governor->record(EDGE_ZONE, new EdgePurgeResponseData(EdgePurgeOutcome::Failed, status: 500));
    expect($governor->state(EDGE_ZONE)->requestsPerSecond)->toBe(2.0);
});

it('cuts the rate when a successful response reports an exhausted rate-limit header', function (): void {
    $governor = EdgePurgeRateGovernor::fromSettings(throttleSettings());

    $rate = $governor->record(EDGE_ZONE, new EdgePurgeResponseData(EdgePurgeOutcome::Succeeded, status: 200, rateLimitRemaining: 0, rateLimitResetSeconds: 12));

    expect($rate->requestsPerSecond)->toBe(1.0)
        ->and($rate->blockedUntil)->toBe(now()->getTimestamp() + 12);
});

it('reads Cloudflare 429 Retry-After and rate-limit headers into the provider response', function (): void {
    config()->set('capell-html-cache.purge.cloudflare.zone_id', str_repeat('a', 32));
    config()->set('capell-html-cache.purge.token', 'edge-token');
    Http::fake([
        'https://api.cloudflare.com/*' => Http::sequence()
            ->push(['success' => false, 'errors' => [['code' => 10429]]], 429, ['Retry-After' => '17'])
            ->push(['success' => true], 200, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '9']),
    ]);

    $purger = resolve(CloudflareCachePurger::class);
    $response = $purger->sendPurge(new EdgeCachePurgeData(urls: ['https://example.test/a']));

    expect($purger->edgePurgeZone())->toBe('cloudflare:' . str_repeat('a', 32))
        ->and($response->outcome)->toBe(EdgePurgeOutcome::Throttled)
        ->and($response->status)->toBe(429)
        ->and($response->retryAfterSeconds)->toBe(17)
        ->and($response->detail)->toContain('10429')->not->toContain('edge-token');

    $response = $purger->sendPurge(new EdgeCachePurgeData(tags: ['page-1']));

    expect($response->isSuccessful())->toBeTrue()
        ->and($response->exhaustedRateLimit())->toBeTrue()
        ->and($response->requestedWaitSeconds())->toBe(9);
});

it('re-dispatches a throttled drain job for the provider wait instead of failing, then finishes', function (): void {
    $logs = captureEdgePurgeLogs();
    Queue::fake();
    runQueuedPurge(new EdgeCachePurgeData(urls: ['https://example.test/a']));
    $token = pushedDrainToken();
    $this->provider->answer(new EdgePurgeResponseData(EdgePurgeOutcome::Throttled, status: 429, retryAfterSeconds: 17, detail: 'rate limited'));

    $job = new JobDecorator(DrainEdgeCachePurgeQueueAction::class, EDGE_ZONE, $token);
    $job->withFakeQueueInteractions();
    $job->handle();

    $job->assertNotReleased();
    $job->assertNotFailed();
    DrainEdgeCachePurgeQueueAction::assertPushed(static fn (DrainEdgeCachePurgeQueueAction $action, array $parameters, JobDecorator $pushed): bool => $pushed->delay === 17);
    expect(EdgePurgePendingQueue::fromSettings(throttleSettings())->status(EDGE_ZONE)->count)->toBe(1)
        ->and($logs)->toHaveCount(1)
        ->and(($logs[0] ?? null)?->level)->toBe('warning')
        ->and(($logs[0] ?? null)?->context)->toMatchArray([
            'zone' => EDGE_ZONE,
            'status' => 429,
            'retry_after_seconds' => 17,
            'learned_requests_per_second' => 1.0,
        ]);

    $this->travel(17)->seconds();
    $retry = new JobDecorator(DrainEdgeCachePurgeQueueAction::class, EDGE_ZONE, $token);
    $retry->withFakeQueueInteractions();
    $retry->handle();

    $retry->assertNotReleased();
    $retry->assertNotFailed();
    expect($this->provider->sentUrls())->toBe(['https://example.test/a', 'https://example.test/a'])
        ->and(EdgePurgePendingQueue::fromSettings(throttleSettings())->status(EDGE_ZONE)->isEmpty())->toBeTrue();
});

it('releases the drain job while the shared bucket is empty and backs off provider failures', function (): void {
    config()->set('capell-html-cache.purge.throttle.burst', 1);
    config()->set('capell-html-cache.purge.throttle.requests_per_second', 0.5);

    $queue = EdgePurgePendingQueue::fromSettings(throttleSettings());
    $token = queueAndClaim($queue, new EdgeCachePurgeData(tags: ['page-1'], urls: ['https://example.test/a']));

    $first = DrainEdgeCachePurgeQueueAction::run(EDGE_ZONE, $token);

    expect($first->sentBatches)->toBe(1)
        ->and($first->releaseAfterSeconds)->toBe(2);

    $this->travel(2)->seconds();
    $this->provider->answer(EdgePurgeResponseData::failed('upstream error', 502));
    $second = DrainEdgeCachePurgeQueueAction::run(EDGE_ZONE, $token);

    expect($second->releaseAfterSeconds)->toBe(45)
        ->and($queue->status(EDGE_ZONE)->count)->toBe(1)
        ->and($queue->ownsDrainer(EDGE_ZONE, $token))->toBeTrue();
});

it('escalates to one full-zone purge when the pending set needs more requests than the threshold', function (): void {
    $logs = captureEdgePurgeLogs();
    // Two requests of 30 URLs each are allowed; a 61st URL needs a third.
    config()->set('capell-html-cache.purge.throttle.escalate_pending_threshold', 2);
    Queue::fake();

    runQueuedPurge(new EdgeCachePurgeData(urls: array_map(static fn (int $page): string => 'https://example.test/' . $page, range(1, 60))));
    expect(EdgePurgePendingQueue::fromSettings(throttleSettings())->status(EDGE_ZONE)->purgeAll)->toBeFalse();

    runQueuedPurge(new EdgeCachePurgeData(urls: ['https://example.test/61']));

    $status = EdgePurgePendingQueue::fromSettings(throttleSettings())->status(EDGE_ZONE);
    expect($status->purgeAll)->toBeTrue()
        ->and($status->count)->toBe(1)
        ->and($status->escalationReason)->toBe('pending_threshold');

    DrainEdgeCachePurgeQueueAction::run(EDGE_ZONE, pushedDrainToken());

    expect($this->provider->sent)->toHaveCount(1)
        ->and($this->provider->sent[0]->purgeAll)->toBeTrue()
        ->and(collect($logs)->pluck('context.reason')->filter()->values()->all())->toBe(['pending_threshold']);
});

it('escalates an overdue backlog to a full-zone purge, and only when configured', function (bool $allowed): void {
    config()->set('capell-html-cache.purge.throttle.escalate_to_full_purge', $allowed);
    $queue = EdgePurgePendingQueue::fromSettings(throttleSettings());
    $token = queueAndClaim($queue, new EdgeCachePurgeData(urls: ['https://example.test/a', 'https://example.test/b']));
    $this->provider->answer(new EdgePurgeResponseData(EdgePurgeOutcome::Throttled, status: 429, retryAfterSeconds: 900));

    expect(DrainEdgeCachePurgeQueueAction::run(EDGE_ZONE, $token)->releaseAfterSeconds)->toBe(900);

    $this->travel(901)->seconds();
    DrainEdgeCachePurgeQueueAction::run(EDGE_ZONE, $token);
    array_shift($this->provider->sent);

    expect(collect($this->provider->sent)->contains(static fn (EdgeCachePurgeData $purge): bool => $purge->purgeAll))->toBe($allowed)
        ->and($this->provider->sentUrls())->toBe($allowed ? [] : ['https://example.test/a', 'https://example.test/b']);
})->with(['escalation allowed' => true, 'escalation disabled' => false]);

it('does not escalate past the threshold when full-zone purges are not allowed', function (): void {
    config()->set('capell-html-cache.purge.throttle.escalate_to_full_purge', false);
    config()->set('capell-html-cache.purge.throttle.escalate_pending_threshold', 5);

    $queue = EdgePurgePendingQueue::fromSettings(throttleSettings());

    $result = $queue->add(EDGE_ZONE, new EdgeCachePurgeData(urls: array_map(static fn (int $page): string => 'https://example.test/' . $page, range(1, 10))));

    expect($result->escalationReason)->toBeNull()
        ->and($queue->status(EDGE_ZONE)->purgeAll)->toBeFalse()
        ->and($queue->status(EDGE_ZONE)->count)->toBe(10);
});

it('remembers the learned rate across restarts until its TTL lapses', function (): void {
    EdgePurgeRateGovernor::fromSettings(throttleSettings())
        ->record(EDGE_ZONE, new EdgePurgeResponseData(EdgePurgeOutcome::Throttled, status: 429, retryAfterSeconds: 5));

    // A restarted worker has no in-memory state: only the shared store remains.
    Cache::purge('edge_purge_file');
    $restarted = EdgePurgeRateGovernor::fromSettings(throttleSettings());

    expect($restarted->hasLearned(EDGE_ZONE))->toBeTrue()
        ->and($restarted->state(EDGE_ZONE)->requestsPerSecond)->toBe(1.0);

    $this->travel(3601)->seconds();

    expect($restarted->hasLearned(EDGE_ZONE))->toBeFalse()
        ->and($restarted->state(EDGE_ZONE)->requestsPerSecond)->toBe(2.0);
});

it('reports the learned rate and backlog in the html-cache health output', function (): void {
    $healthy = (new HtmlCacheHealthCheck)->edgePurgeThrottleCheck();
    expect($healthy->message)->toBe((string) __('capell-html-cache::health.edge_purge_throttle.passed', [
        'zone' => EDGE_ZONE, 'rate' => '2.00', 'ceiling' => '2.00', 'pending' => 0, 'age' => 0, 'status' => '-', 'waiting' => 0,
    ]));

    $queue = EdgePurgePendingQueue::fromSettings(throttleSettings());
    $queue->add(EDGE_ZONE, new EdgeCachePurgeData(urls: ['https://example.test/a']));
    EdgePurgeRateGovernor::fromSettings(throttleSettings())
        ->record(EDGE_ZONE, new EdgePurgeResponseData(EdgePurgeOutcome::Throttled, status: 429, retryAfterSeconds: 40));

    $status = InspectEdgeCachePurgeThrottleAction::run();
    $check = (new HtmlCacheHealthCheck)->edgePurgeThrottleCheck();

    expect($status->isActive())->toBeTrue()
        ->and($status->learned)->toBeTrue()
        ->and($status->pending->count)->toBe(1)
        ->and($check->passed)->toBeTrue()
        ->and($check->message)->toBe((string) __('capell-html-cache::health.edge_purge_throttle.paused', [
            'zone' => EDGE_ZONE, 'rate' => '1.00', 'ceiling' => '2.00', 'pending' => 1, 'age' => 0, 'status' => 429, 'waiting' => 40,
        ]));

    $this->travel(901)->seconds();

    $overdue = (new HtmlCacheHealthCheck)->edgePurgeThrottleCheck();

    expect($overdue->passed)->toBeFalse()
        ->and($overdue->message)->toBe((string) __('capell-html-cache::health.edge_purge_throttle.backlogged', [
            'zone' => EDGE_ZONE, 'rate' => '1.00', 'ceiling' => '2.00', 'pending' => 1, 'age' => 901, 'status' => 429, 'waiting' => 0,
        ]))
        ->and($overdue->remediation)->toBe((string) __('capell-html-cache::health.edge_purge_throttle.backlogged_remediation'));
});

it('sends queued purges directly when the purger has no rate-limited zone', function (): void {
    app()->instance(CachePurger::class, new NullCachePurger);
    Queue::fake();

    runQueuedPurge(new EdgeCachePurgeData(urls: ['https://example.test/a']))->assertNotReleased();

    DrainEdgeCachePurgeQueueAction::assertNotPushed();
    expect((new HtmlCacheHealthCheck)->edgePurgeThrottleCheck()->passed)->toBeTrue();
});

it('formats the active edge purge rate using the current locale', function (): void {
    $locale = app()->getLocale();
    app()->setLocale('de');
    try {
        $check = (new HtmlCacheHealthCheck)->edgePurgeThrottleCheck();
        expect($check->message)->toContain('2,00');
    } finally {
        app()->setLocale($locale);
    }
});

it('keeps throttled queued purges from failing the purge job', function (): void {
    Queue::fake();
    $this->provider->answer(new EdgePurgeResponseData(EdgePurgeOutcome::Throttled, status: 429, retryAfterSeconds: 60));

    $job = runQueuedPurge(new EdgeCachePurgeData(tags: ['page-1']));

    $job->assertNotFailed();
    $job->assertNotReleased();

    expect($this->provider->sent)->toBe([]);
});

it('sends queued purges directly and fails health when the pacing store is local to one process', function (): void {
    config()->set('capell-html-cache.purge.throttle.store', 'array');
    Queue::fake();

    runQueuedPurge(new EdgeCachePurgeData(urls: ['https://example.test/a']))->assertNotReleased();

    $check = (new HtmlCacheHealthCheck)->edgePurgeThrottleCheck();

    DrainEdgeCachePurgeQueueAction::assertNotPushed();
    expect($this->provider->sentUrls())->toBe(['https://example.test/a'])
        ->and($check->passed)->toBeFalse()
        ->and($check->remediation)->toContain('redis');
});

it('lets a drain job whose claim lapsed in a busy queue resume, but not one another drainer replaced', function (): void {
    $queue = EdgePurgePendingQueue::fromSettings(throttleSettings());
    $lapsed = queueAndClaim($queue, new EdgeCachePurgeData(urls: ['https://example.test/a']));

    $this->travel(200)->seconds();

    expect(DrainEdgeCachePurgeQueueAction::run(EDGE_ZONE, $lapsed)->sentBatches)->toBe(1)
        ->and($this->provider->sentUrls())->toBe(['https://example.test/a']);

    $stale = queueAndClaim($queue, new EdgeCachePurgeData(urls: ['https://example.test/b']));
    $this->travel(200)->seconds();
    $replacement = queueAndClaim($queue, new EdgeCachePurgeData(urls: ['https://example.test/c']));

    expect($replacement)->not->toBeNull()->not->toBe($stale)
        ->and(DrainEdgeCachePurgeQueueAction::run(EDGE_ZONE, $stale)->sentBatches)->toBe(0)
        ->and(DrainEdgeCachePurgeQueueAction::run(EDGE_ZONE, $replacement)->sentBatches)->toBe(1)
        ->and($this->provider->sentUrls())->toBe(['https://example.test/a', 'https://example.test/b', 'https://example.test/c']);
});

it('turns a site-wide refresh fanning out one purge job per URL into batched requests without failed jobs', function (): void {
    $logs = captureEdgePurgeLogs();
    Queue::fake();
    $urls = array_map(static fn (int $page): string => 'https://example.test/refreshed-' . $page, range(1, 262));
    $this->provider->answer(
        EdgePurgeResponseData::succeeded(),
        new EdgePurgeResponseData(EdgePurgeOutcome::Throttled, status: 429, retryAfterSeconds: 25, detail: 'rate limited'),
    );

    foreach ($urls as $url) {
        $job = runQueuedPurge(new EdgeCachePurgeData(urls: [$url]));
        $job->assertNotFailed();
        $job->assertNotReleased();
    }

    DrainEdgeCachePurgeQueueAction::assertPushed(1);
    $token = pushedDrainToken();

    $drain = new JobDecorator(DrainEdgeCachePurgeQueueAction::class, EDGE_ZONE, $token);
    $drain->withFakeQueueInteractions();
    $drain->handle();
    $drain->assertNotReleased();
    $drain->assertNotFailed();
    DrainEdgeCachePurgeQueueAction::assertPushed(static fn (DrainEdgeCachePurgeQueueAction $action, array $parameters, JobDecorator $pushed): bool => $pushed->delay === 25);

    $failure = collect($logs)->first(static fn (MessageLogged $log): bool => $log->level === 'warning');
    expect($failure?->context)->toMatchArray(['status' => 429, 'retry_after_seconds' => 25, 'items' => 30, 'kind' => 'urls']);

    // The halved rate also halves the burst, so the rest drains in paced runs.
    $this->travel(25)->seconds();
    $waits = [];

    for ($run = 0; $run < 20; $run++) {
        $result = DrainEdgeCachePurgeQueueAction::run(EDGE_ZONE, $token);

        if ($result->finished()) {
            break;
        }

        $waits[] = $result->releaseAfterSeconds;
        $this->travel($result->releaseAfterSeconds ?? 1)->seconds();
    }

    expect($result->finished())->toBeTrue()
        ->and($waits)->not->toBeEmpty()
        ->and($this->provider->sent)->toHaveCount(10)
        ->and(array_values(array_unique($this->provider->sentUrls())))->toBe($urls)
        ->and(collect($this->provider->sent)->every(static fn (EdgeCachePurgeData $purge): bool => count($purge->urls) <= 30))->toBeTrue();
});

it('escalates a site-wide per-URL fan-out needing more requests than the threshold to one full-zone purge', function (): void {
    // 262 URLs need 9 requests of 30.
    config()->set('capell-html-cache.purge.throttle.escalate_pending_threshold', 8);
    Queue::fake();

    foreach (range(1, 262) as $page) {
        runQueuedPurge(new EdgeCachePurgeData(urls: ['https://example.test/refreshed-' . $page]))->assertNotFailed();
    }

    DrainEdgeCachePurgeQueueAction::run(EDGE_ZONE, pushedDrainToken());

    expect($this->provider->sent)->toHaveCount(1)
        ->and($this->provider->sent[0]->purgeAll)->toBeTrue();
});

it('coalesces a 501-clear burst into provider-sized requests without escalating to a full-zone purge', function (): void {
    $defaults = new EdgePurgeThrottleSettingsData;
    config()->set('capell-html-cache.purge.throttle.max_urls_per_request', $defaults->maxUrlsPerRequest);
    config()->set('capell-html-cache.purge.throttle.max_tags_per_request', $defaults->maxTagsPerRequest);
    config()->set('capell-html-cache.purge.throttle.escalate_pending_threshold', $defaults->escalatePendingThreshold);
    Queue::fake();

    // Each page clear purges its URL, its page tag and its URL tag.
    foreach (range(1, 501) as $page) {
        $url = 'https://example.test/cleared-' . $page;
        runQueuedPurge(new EdgeCachePurgeData(tags: ['page-' . $page, ResolveEdgeCacheTagsAction::urlTag($url)], urls: [$url]))->assertNotFailed();
    }

    $token = pushedDrainToken();

    for ($run = 0; $run < 50; $run++) {
        $result = DrainEdgeCachePurgeQueueAction::run(EDGE_ZONE, $token);

        if ($result->finished()) {
            break;
        }

        $this->travel((int) ceil($result->releaseAfterSeconds ?? 1))->seconds();
    }

    $sent = collect($this->provider->sent);
    $urlRequests = $sent->filter(static fn (EdgeCachePurgeData $purge): bool => $purge->urls !== []);
    $tagRequests = $sent->filter(static fn (EdgeCachePurgeData $purge): bool => $purge->tags !== []);

    expect($result->finished())->toBeTrue()
        ->and($defaults->maxUrlsPerRequest)->toBe(100)
        ->and($defaults->maxTagsPerRequest)->toBe(100)
        ->and($sent->contains(static fn (EdgeCachePurgeData $purge): bool => $purge->purgeAll))->toBeFalse()
        ->and($urlRequests->count())->toBeLessThanOrEqual((int) ceil(501 / 100))
        ->and($tagRequests->count())->toBeLessThanOrEqual((int) ceil(1002 / 100))
        ->and(count(array_unique($this->provider->sentUrls())))->toBe(501)
        ->and(count(array_unique($this->provider->sentTags())))->toBe(1002);
});

it('sends url-scoped purges without their tags when tag purges cannot be paced', function (string $pacing): void {
    $logs = captureEdgePurgeLogs();

    if ($pacing === 'sync queue') {
        config()->set('queue.default', 'sync');
    } else {
        config()->set('capell-html-cache.purge.throttle.store', 'array');
    }

    foreach (range(1, 40) as $page) {
        $url = 'https://example.test/cleared-' . $page;
        $job = new JobDecorator(PurgeEdgeCacheAction::class, new EdgeCachePurgeData(tags: ['page-' . $page, ResolveEdgeCacheTagsAction::urlTag($url)], urls: [$url]), true);
        $job->withFakeQueueInteractions();
        $job->handle();
        $job->assertNotFailed();
        $job->assertNotReleased();
    }

    $skipped = collect($logs)->filter(static fn (MessageLogged $log): bool => str_contains($log->message, 'without its tags'));

    expect($this->provider->sentTags())->toBe([])
        ->and($this->provider->sentUrls())->toHaveCount(40)
        ->and($skipped)->toHaveCount(1)
        ->and($skipped->first()?->level)->toBe('warning');
})->with(['sync queue', 'unshared pacing store']);

it('still sends explicitly requested tags directly when tag purges cannot be paced', function (): void {
    config()->set('queue.default', 'sync');

    runQueuedPurge(new EdgeCachePurgeData(tags: ['site-1']))->assertNotFailed();

    expect($this->provider->sentTags())->toBe(['site-1']);
});

it('logs the provider status, Retry-After and purge size when a direct Cloudflare purge is refused', function (): void {
    $logs = captureEdgePurgeLogs();
    config()->set('capell-html-cache.purge.cloudflare.zone_id', str_repeat('b', 32));
    config()->set('capell-html-cache.purge.token', 'edge-token');
    Http::fake(['https://api.cloudflare.com/*' => Http::response(['success' => false], 429, ['Retry-After' => '60'])]);

    expect(resolve(CloudflareCachePurger::class)->purge(new EdgeCachePurgeData(urls: ['https://example.test/a', 'https://example.test/b'])))->toBeFalse()
        ->and(($logs[0] ?? null)?->context)->toMatchArray([
            'zone' => 'cloudflare:' . str_repeat('b', 32),
            'items' => 2,
            'status' => 429,
            'retry_after_seconds' => 60,
        ])
        ->and(json_encode(($logs[0] ?? null)?->context))->not->toContain('edge-token');
});

it('keeps a purge queued while a full-zone purge is in flight', function (): void {
    $queue = EdgePurgePendingQueue::fromSettings(throttleSettings());
    $queue->add(EDGE_ZONE, new EdgeCachePurgeData(purgeAll: true));

    $inFlight = $queue->next(EDGE_ZONE) ?? throw new RuntimeException('Expected a pending batch.');
    $queue->add(EDGE_ZONE, new EdgeCachePurgeData(urls: ['https://example.test/changed-during-purge']));
    $queue->complete(EDGE_ZONE, $inFlight);

    expect($inFlight->purgeAll)->toBeTrue()
        ->and($queue->next(EDGE_ZONE)?->purge()->urls)->toBe(['https://example.test/changed-during-purge']);
});

it('sends purges directly on the sync queue, where a released drain job would never run again', function (): void {
    config()->set('queue.default', 'sync');
    $this->provider->answer(new EdgePurgeResponseData(EdgePurgeOutcome::Throttled, status: 429, retryAfterSeconds: 30));

    expect(static fn (): JobDecorator => runQueuedPurge(new EdgeCachePurgeData(urls: ['https://example.test/a'])))
        ->toThrow(RuntimeException::class, 'Unable to purge the edge HTML cache.');

    expect($this->provider->sentUrls())->toBe(['https://example.test/a'])
        ->and(EdgePurgePendingQueue::fromSettings(throttleSettings())->status(EDGE_ZONE)->isEmpty())->toBeTrue()
        ->and(EdgePurgePendingQueue::fromSettings(throttleSettings())->status(EDGE_ZONE)->hasDrainer)->toBeFalse();
});

it('re-dispatches a waiting drain job with a fresh retry deadline instead of releasing it', function (): void {
    Queue::fake();
    $token = queueAndClaim(EdgePurgePendingQueue::fromSettings(throttleSettings()), new EdgeCachePurgeData(urls: ['https://example.test/a']));
    $this->provider->answer(new EdgePurgeResponseData(EdgePurgeOutcome::Throttled, status: 429, retryAfterSeconds: 17));

    $job = new JobDecorator(DrainEdgeCachePurgeQueueAction::class, EDGE_ZONE, $token);
    $job->withFakeQueueInteractions();
    $job->handle();

    $job->assertNotReleased();
    $job->assertNotFailed();
    DrainEdgeCachePurgeQueueAction::assertPushed(1, static fn (DrainEdgeCachePurgeQueueAction $action, array $parameters, JobDecorator $pushed): bool => $parameters === [EDGE_ZONE, $token]
        && $pushed->delay === 17);
});

it('gives up the drainer claim when a drain job fails, so the next purge starts a new drainer', function (): void {
    $queue = EdgePurgePendingQueue::fromSettings(throttleSettings());
    $token = queueAndClaim($queue, new EdgeCachePurgeData(urls: ['https://example.test/a']));

    // Laravel calls failed() on the job, which hands over to the action's jobFailed().
    new JobDecorator(DrainEdgeCachePurgeQueueAction::class, EDGE_ZONE, $token)->failed(new RuntimeException('worker died'));

    expect($queue->status(EDGE_ZONE)->hasDrainer)->toBeFalse()
        ->and($queue->add(EDGE_ZONE, new EdgeCachePurgeData(urls: ['https://example.test/b']))->drainerToken)->not->toBeNull();
});

it('rotates a single purge the provider refuses as a bad request behind other purges, then gives it up at error level', function (): void {
    $logs = captureEdgePurgeLogs();
    config()->set('capell-html-cache.purge.throttle.max_urls_per_request', 1);
    config()->set('capell-html-cache.purge.throttle.max_batch_failures', 2);
    config()->set('capell-html-cache.purge.throttle.escalate_to_full_purge', false);

    $this->provider->rejectWhen(static fn (EdgeCachePurgeData $purge): bool => in_array('https://example.test/rejected', $purge->urls, true));
    $queue = EdgePurgePendingQueue::fromSettings(throttleSettings());
    $token = queueAndClaim($queue, new EdgeCachePurgeData(urls: ['https://example.test/rejected']));
    $queue->add(EDGE_ZONE, new EdgeCachePurgeData(urls: ['https://example.test/accepted']));

    $result = DrainEdgeCachePurgeQueueAction::run(EDGE_ZONE, $token);
    $giveUp = collect($logs)->first(static fn (MessageLogged $log): bool => str_contains($log->message, 'gave up'));

    expect($this->provider->sentUrls())->toBe(['https://example.test/rejected', 'https://example.test/accepted', 'https://example.test/rejected'])
        ->and($result->finished())->toBeTrue()
        ->and($queue->status(EDGE_ZONE)->isEmpty())->toBeTrue()
        ->and($giveUp?->level)->toBe('error')
        ->and($giveUp?->context)->toMatchArray(['zone' => EDGE_ZONE, 'items' => 1, 'attempts' => 2, 'status' => 400]);
});

it('splits a refused batch down to the bad entry so its batch-mates are still purged', function (): void {
    config()->set('capell-html-cache.purge.throttle.max_urls_per_request', 8);
    config()->set('capell-html-cache.purge.throttle.max_batch_failures', 1);

    $urls = array_map(static fn (int $page): string => 'https://example.test/page-' . $page, range(1, 8));
    $bad = 'https://example.test/page-6';
    $this->provider->rejectWhen(static fn (EdgeCachePurgeData $purge): bool => in_array($bad, $purge->urls, true), 422);
    $queue = EdgePurgePendingQueue::fromSettings(throttleSettings());
    $token = queueAndClaim($queue, new EdgeCachePurgeData(urls: $urls));

    expect(DrainEdgeCachePurgeQueueAction::run(EDGE_ZONE, $token)->finished())->toBeTrue();

    $accepted = collect($this->provider->sent)
        ->reject(static fn (EdgeCachePurgeData $purge): bool => in_array($bad, $purge->urls, true))
        ->flatMap(static fn (EdgeCachePurgeData $purge): array => $purge->urls)
        ->sort()->values()->all();
    $dropped = $queue->dropped(EDGE_ZONE);

    expect($accepted)->toBe(collect($urls)->reject(static fn (string $url): bool => $url === $bad)->sort()->values()->all())
        ->and(collect($this->provider->sent)->map(static fn (EdgeCachePurgeData $purge): int => count($purge->urls))->all())->toBe([8, 4, 4, 2, 1, 1, 2])
        ->and($dropped->count)->toBe(1)
        ->and($dropped->sample)->toBe([$bad])
        ->and($dropped->lastStatus)->toBe(422);
});

it('never drops purges the provider refuses because of credentials, and backs off with an error log', function (int $status): void {
    $logs = captureEdgePurgeLogs();
    config()->set('capell-html-cache.purge.throttle.max_batch_failures', 1);
    $this->provider->rejectWhen(static fn (EdgeCachePurgeData $purge): bool => true, $status);
    $queue = EdgePurgePendingQueue::fromSettings(throttleSettings());
    $token = queueAndClaim($queue, new EdgeCachePurgeData(urls: ['https://example.test/a', 'https://example.test/b']));

    foreach (range(1, 3) as $attempt) {
        expect(DrainEdgeCachePurgeQueueAction::run(EDGE_ZONE, $token)->releaseAfterSeconds)->toBe(45);
        $this->travel(45)->seconds();
    }

    expect($queue->status(EDGE_ZONE)->count)->toBe(2)
        ->and($queue->dropped(EDGE_ZONE)->count)->toBe(0)
        ->and(collect($logs)->contains(static fn (MessageLogged $log): bool => $log->level === 'error' && ($log->context['status'] ?? null) === $status))->toBeTrue();
})->with(['expired token' => 401, 'revoked permission' => 403]);

it('reports dropped purges in the health check until a full-zone purge covers them', function (): void {
    config()->set('capell-html-cache.purge.throttle.max_batch_failures', 1);
    $this->provider->rejectWhen(static fn (EdgeCachePurgeData $purge): bool => in_array('https://example.test/bad', $purge->urls, true));
    $queue = EdgePurgePendingQueue::fromSettings(throttleSettings());
    $token = queueAndClaim($queue, new EdgeCachePurgeData(urls: ['https://example.test/bad']));
    DrainEdgeCachePurgeQueueAction::run(EDGE_ZONE, $token);

    $check = (new HtmlCacheHealthCheck)->edgePurgeThrottleCheck();

    expect($check->passed)->toBeFalse()
        ->and($check->severity)->toBe(DoctorCheckSeverity::Warning)
        ->and($check->message)->toContain('https://example.test/bad')
        ->and($check->remediation)->not->toBeNull();

    $fullPurge = queueAndClaim($queue, new EdgeCachePurgeData(purgeAll: true));
    DrainEdgeCachePurgeQueueAction::run(EDGE_ZONE, $fullPurge);

    expect($queue->dropped(EDGE_ZONE)->count)->toBe(0)
        ->and((new HtmlCacheHealthCheck)->edgePurgeThrottleCheck()->passed)->toBeTrue();
});

it('sends purges directly on queue drivers that run delayed jobs at once', function (string $driver): void {
    config()->set('queue.connections.edge-purge-immediate', ['driver' => $driver]);
    config()->set('queue.default', 'edge-purge-immediate');

    $this->provider->answer(new EdgePurgeResponseData(EdgePurgeOutcome::Throttled, status: 429, retryAfterSeconds: 30));

    expect(static fn (): JobDecorator => runQueuedPurge(new EdgeCachePurgeData(urls: ['https://example.test/a'])))
        ->toThrow(RuntimeException::class, 'Unable to purge the edge HTML cache.');

    expect($this->provider->sentUrls())->toBe(['https://example.test/a'])
        ->and(EdgePurgePendingQueue::fromSettings(throttleSettings())->status(EDGE_ZONE)->isEmpty())->toBeTrue();
})->with(['deferred', 'background']);

it('keeps a batch queued through a provider outage instead of counting it towards giving up', function (): void {
    config()->set('capell-html-cache.purge.throttle.max_batch_failures', 1);
    $queue = EdgePurgePendingQueue::fromSettings(throttleSettings());
    $token = queueAndClaim($queue, new EdgeCachePurgeData(urls: ['https://example.test/a']));
    $this->provider->answer(EdgePurgeResponseData::failed('bad gateway', 502), EdgePurgeResponseData::fromException(new RuntimeException('connection reset')));

    DrainEdgeCachePurgeQueueAction::run(EDGE_ZONE, $token);
    $this->travel(45)->seconds();
    DrainEdgeCachePurgeQueueAction::run(EDGE_ZONE, $token);

    expect($queue->status(EDGE_ZONE)->count)->toBe(1);

    $this->travel(45)->seconds();

    expect(DrainEdgeCachePurgeQueueAction::run(EDGE_ZONE, $token)->finished())->toBeTrue()
        ->and($this->provider->sentUrls())->toBe(['https://example.test/a', 'https://example.test/a', 'https://example.test/a']);
});

/**
 * Give up one URL in the zone the way the drain does after a bad request.
 */
function dropEdgePurge(string $zone, string $url): void
{
    config()->set('capell-html-cache.purge.throttle.max_batch_failures', 1);
    $queue = EdgePurgePendingQueue::fromSettings(throttleSettings());
    $token = $queue->add($zone, new EdgeCachePurgeData(urls: [$url]))->drainerToken;
    $batch = $queue->next($zone) ?? throw new RuntimeException('Expected a pending batch.');

    expect($queue->reject($zone, $batch, 400))->toBe([$url]);

    // The drain that gave it up finishes, as DrainEdgeCachePurgeQueueAction would.
    if ($token !== null) {
        $queue->releaseDrainerIfEmpty($zone, $token);
    }
}

function useCloudflareZone(): string
{
    config()->set('capell-html-cache.purge.cloudflare.zone_id', str_repeat('c', 32));
    config()->set('capell-html-cache.purge.token', 'edge-token');

    app()->forgetInstance(CachePurger::class);
    app()->instance(CachePurger::class, resolve(CloudflareCachePurger::class));
    Http::fake(['https://api.cloudflare.com/*' => Http::response(['success' => true])]);

    return 'cloudflare:' . str_repeat('c', 32);
}

it('clears dropped purges after a direct full-zone purge such as clearing the whole HTML cache', function (): void {
    $zone = useCloudflareZone();
    $queue = EdgePurgePendingQueue::fromSettings(throttleSettings());
    dropEdgePurge($zone, 'https://example.test/bad');

    PurgeEdgeCacheAction::run(new EdgeCachePurgeData(purgeAll: true));

    expect($queue->dropped($zone)->count)->toBe(0);
});

it('forgets a dropped purge once that URL is purged successfully, directly or queued', function (): void {
    $zone = useCloudflareZone();
    $queue = EdgePurgePendingQueue::fromSettings(throttleSettings());
    dropEdgePurge($zone, 'https://example.test/direct');
    dropEdgePurge($zone, 'https://example.test/queued');

    PurgeEdgeCacheAction::run(new EdgeCachePurgeData(urls: ['https://example.test/direct']));

    expect($queue->dropped($zone)->sample)->toBe(['https://example.test/queued']);

    $token = $queue->add($zone, new EdgeCachePurgeData(urls: ['https://example.test/queued']))->drainerToken
        ?? throw new RuntimeException('The purge did not claim the drainer.');
    DrainEdgeCachePurgeQueueAction::run($zone, $token);

    expect($queue->dropped($zone)->count)->toBe(0)
        ->and((new HtmlCacheHealthCheck)->edgePurgeThrottleCheck()->passed)->toBeTrue();
});

it('lets the dropped-purge warning expire after its configured lifetime and says how to clear it', function (): void {
    config()->set('capell-html-cache.purge.throttle.dropped_record_ttl_seconds', 3600);
    $queue = EdgePurgePendingQueue::fromSettings(throttleSettings());
    dropEdgePurge(EDGE_ZONE, 'https://example.test/bad');

    $warning = (new HtmlCacheHealthCheck)->edgePurgeThrottleCheck();

    expect($warning->passed)->toBeFalse()
        ->and($warning->remediation)->toContain('full-zone purge')->toContain('1 hour');

    $this->travel(3601)->seconds();

    expect($queue->dropped(EDGE_ZONE)->count)->toBe(0)
        ->and((new HtmlCacheHealthCheck)->edgePurgeThrottleCheck()->passed)->toBeTrue();
});
