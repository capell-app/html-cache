<?php

declare(strict_types=1);

use Capell\Core\Models\SiteDomain;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Capell\HtmlCache\Support\Cache\HtmlCachePublicationGuard;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
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
    config()->set('capell-html-cache.request_coalescing.lock_seconds', 15);
    config()->set('capell-html-cache.request_coalescing.wait_seconds', 1);
    config()->set('capell-html-cache.request_coalescing.uncacheable_seconds', 5);
    Sleep::fake(syncWithCarbon: true);
});

afterEach(function (): void {
    Sleep::fake(false);
    $this->travelBack();
});

it('bounds renderer entries while a cold render outlives the coalescing wait or lease', function (bool $expiredLease): void {
    SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $request = Request::create('https://example.test/slow');
    app()->instance('request', $request);
    $renders = 0;
    $leader = new Fiber(function () use ($request, &$renders): Response {
        return resolve(HtmlCacheMiddleware::class)->handle($request, function () use (&$renders): Response {
            $renders++;
            Fiber::suspend();

            return response('Completed slow render', 200, ['Content-Type' => 'text/html']);
        });
    });
    $leader->start();

    if ($expiredLease) {
        $this->travel(16)->seconds();
    }

    try {
        for ($contender = 0; $contender < 3; $contender++) {
            $waiting = Request::create($request->fullUrl());
            app()->instance('request', $waiting);
            $startedAt = now();
            $response = resolve(HtmlCacheMiddleware::class)->handle($waiting, function () use (&$renders): Response {
                $renders++;

                return response('Duplicate render', 200, ['Content-Type' => 'text/html']);
            });

            expect($renders)->toBe(1)
                ->and($startedAt->diffInMilliseconds(now()))->toBeLessThanOrEqual(1000)
                ->and($response->getStatusCode())->toBe(503)
                ->and($response->headers->get('Retry-After'))->toBe('1')
                ->and($response->headers->get('Cache-Control'))->toContain('private', 'no-store')
                ->and($response->getContent())->toBe(__('capell-html-cache::cache.retry'));
        }
    } finally {
        app()->instance('request', $request);
        $leader->resume();
    }

    $hit = resolve(HtmlCacheMiddleware::class)->handle($request, function () use (&$renders): Response {
        $renders++;

        return response('Unexpected render');
    });

    $completed = $leader->getReturn();
    $this->assertInstanceOf(Response::class, $completed);

    expect($renders)->toBe(1)
        ->and($completed->getStatusCode())->toBe(200)
        ->and($hit->getContent())->toBe('Completed slow render')
        ->and($hit->headers->get('X-Frontend-Cache'))->toBe('HIT');
})->with(['wait expiry' => false, 'lease expiry' => true]);

it('rechecks eligible cached bytes at the coalescing timeout without rendering', function (bool $errorPage): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $request = Request::create('https://example.test/arriving');
    app()->instance('request', $request);
    $path = resolve(HtmlCachePathResolver::class)->pathForUrl('/arriving', $domain, error: $errorPage);
    $lock = Cache::lock('capell-html-cache:render:' . hash('sha256', $request->fullUrl()), 15);
    expect($lock->get())->toBeTrue();
    Sleep::whenFakingSleep(static function () use ($path): void {
        Storage::disk('page_cache')->put($path, 'Eligible cached bytes');
    });
    $renders = 0;

    try {
        $response = resolve(HtmlCacheMiddleware::class)->handle($request, function () use (&$renders): Response {
            $renders++;

            return response('Duplicate render', 200, ['Content-Type' => 'text/html']);
        });

        expect($renders)->toBe(0)
            ->and($response->getStatusCode())->toBe($errorPage ? 404 : 200)
            ->and($response->getContent())->toBe('Eligible cached bytes')
            ->and($response->headers->get('X-Frontend-Cache'))->toBe('HIT');
    } finally {
        $lock->release();
    }
})->with(['page' => false, 'error page' => true]);

function uncacheableCoalescingResponse(string $kind): Response
{
    return match ($kind) {
        'cookie' => response('Personalised response', 200, ['Content-Type' => 'text/html'])
            ->withCookie(cookie('personalisation', 'visitor')),
        'private' => response('Private response', 200, ['Content-Type' => 'text/html', 'Cache-Control' => 'private, no-store']),
        'redirect' => redirect('/destination'),
        'server error' => response('Origin failure', 500, ['Content-Type' => 'text/html']),
        default => throw new InvalidArgumentException('Unknown uncacheable response fixture: ' . $kind),
    };
}

it('renders waiting and subsequent requests normally after an uncacheable owner response', function (string $kind): void {
    $this->freezeTime();
    SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $ownerRequest = Request::create('https://example.test/uncacheable');
    $waitingRequest = Request::create($ownerRequest->fullUrl());
    $renders = 0;
    $owner = new Fiber(function () use ($ownerRequest, $kind, &$renders): Response {
        app()->instance('request', $ownerRequest);

        return resolve(HtmlCacheMiddleware::class)->handle($ownerRequest, function () use ($kind, &$renders): Response {
            $renders++;
            Fiber::suspend();

            return uncacheableCoalescingResponse($kind);
        });
    });
    $subsequent = new Fiber(function () use ($ownerRequest, $kind, &$renders): Response {
        $request = Request::create($ownerRequest->fullUrl());
        app()->instance('request', $request);

        return resolve(HtmlCacheMiddleware::class)->handle($request, function () use ($kind, &$renders): Response {
            $renders++;
            Fiber::suspend();

            return uncacheableCoalescingResponse($kind);
        });
    });
    $owner->start();
    Sleep::whenFakingSleep(static function () use ($owner, $ownerRequest, $subsequent, $waitingRequest): void {
        if (! $owner->isSuspended()) {
            return;
        }

        app()->instance('request', $ownerRequest);
        $owner->resume();
        $subsequent->start();
        app()->instance('request', $waitingRequest);
    });

    try {
        app()->instance('request', $waitingRequest);
        $startedAt = now();
        $waitingResponse = resolve(HtmlCacheMiddleware::class)->handle($waitingRequest, function () use ($kind, &$renders): Response {
            $renders++;

            return uncacheableCoalescingResponse($kind);
        });

        expect($waitingResponse->getStatusCode())->toBe(uncacheableCoalescingResponse($kind)->getStatusCode())
            ->and($waitingResponse->getContent())->toBe(uncacheableCoalescingResponse($kind)->getContent())
            ->and($startedAt->diffInMilliseconds(now()))->toBeLessThanOrEqual(250)
            ->and($renders)->toBe(3);

        $laterRequest = Request::create($ownerRequest->fullUrl());
        app()->instance('request', $laterRequest);
        $startedAt = now();
        $laterResponse = resolve(HtmlCacheMiddleware::class)->handle($laterRequest, function () use ($kind, &$renders): Response {
            $renders++;

            return uncacheableCoalescingResponse($kind);
        });

        expect($laterResponse->getStatusCode())->toBe($waitingResponse->getStatusCode())
            ->and($laterResponse->headers->getCookies())->toEqual($waitingResponse->headers->getCookies())
            ->and($laterResponse->headers->get('Location'))->toBe($waitingResponse->headers->get('Location'))
            ->and($startedAt->diffInMilliseconds(now()))->toBe(0.0)
            ->and($renders)->toBe(4)
            ->and(Storage::disk('page_cache')->allFiles())->toBe([]);
    } finally {
        app()->instance('request', $ownerRequest);

        if ($owner->isSuspended()) {
            $owner->resume();
        }

        if ($subsequent->isSuspended()) {
            $subsequent->resume();
        }
    }
})->with(['cookie', 'private', 'redirect', 'server error']);

it('expires the uncacheable marker at the configured TTL without bypassing other URLs', function (): void {
    SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $url = 'https://example.test/temporarily-private';
    $request = Request::create($url);
    app()->instance('request', $request);
    resolve(HtmlCacheMiddleware::class)->handle($request, static fn (): Response => uncacheableCoalescingResponse('private'));

    $lock = Cache::lock('capell-html-cache:render:' . hash('sha256', $url), 60);
    $otherUrl = 'https://example.test/other';
    $otherLock = Cache::lock('capell-html-cache:render:' . hash('sha256', $otherUrl), 60);
    expect($lock->get())->toBeTrue()
        ->and($otherLock->get())->toBeTrue();

    try {
        $request = Request::create($url);
        app()->instance('request', $request);
        $response = resolve(HtmlCacheMiddleware::class)->handle($request, static fn (): Response => uncacheableCoalescingResponse('private'));
        expect($response->getStatusCode())->toBe(200);

        $otherRequest = Request::create($otherUrl);
        app()->instance('request', $otherRequest);
        $otherResponse = resolve(HtmlCacheMiddleware::class)->handle($otherRequest, static fn (): Response => response('Unexpected render'));
        expect($otherResponse->getStatusCode())->toBe(503);

        $this->travel(6)->seconds();
        $request = Request::create($url);
        app()->instance('request', $request);
        $expiredResponse = resolve(HtmlCacheMiddleware::class)->handle($request, static fn (): Response => response('Unexpected render'));
        expect($expiredResponse->getStatusCode())->toBe(503);
    } finally {
        $lock->release();
        $otherLock->release();
    }

    $request = Request::create($url);
    app()->instance('request', $request);
    $response = resolve(HtmlCacheMiddleware::class)->handle($request, static fn (): Response => response('Public again', 200, ['Content-Type' => 'text/html']));
    $hit = resolve(HtmlCacheMiddleware::class)->handle($request, static fn (): Response => response('Unexpected render'));

    expect($response->getStatusCode())->toBe(200)
        ->and($request->attributes->get(HtmlCacheMiddleware::CACHE_WRITE_SUCCEEDED_ATTRIBUTE))->toBeTrue()
        ->and($hit->getContent())->toBe('Public again')
        ->and($hit->headers->get('X-Frontend-Cache'))->toBe('HIT');
});

it('renders without coalescing when cache writes are disabled', function (): void {
    SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    config()->set('capell-html-cache.write_enabled', false);
    $request = Request::create('https://example.test/read-only');
    app()->instance('request', $request);
    $lock = Cache::lock('capell-html-cache:render:' . hash('sha256', $request->fullUrl()), 15);
    expect($lock->get())->toBeTrue();

    try {
        $response = resolve(HtmlCacheMiddleware::class)->handle($request, static fn (): Response => response('Uncached HTML', 200, ['Content-Type' => 'text/html']));

        expect($response->getStatusCode())->toBe(200)
            ->and($response->getContent())->toBe('Uncached HTML')
            ->and($request->attributes->get(HtmlCacheMiddleware::CACHE_WRITE_SUCCEEDED_ATTRIBUTE))->toBeFalse()
            ->and(Storage::disk('page_cache')->allFiles())->toBe([]);
        Sleep::assertNeverSlept();
    } finally {
        $lock->release();
    }
});

it('preserves render exceptions and bypasses coalescing after the failed owner', function (): void {
    SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $request = Request::create('https://example.test/render-exception');
    app()->instance('request', $request);

    expect(fn (): Response => resolve(HtmlCacheMiddleware::class)->handle($request, static function (): never {
        throw new RuntimeException('Origin render failed');
    }))->toThrow(RuntimeException::class, 'Origin render failed');

    $lock = Cache::lock('capell-html-cache:render:' . hash('sha256', $request->fullUrl()), 15);
    expect($lock->get())->toBeTrue();

    try {
        $request = Request::create($request->fullUrl());
        app()->instance('request', $request);
        $response = resolve(HtmlCacheMiddleware::class)->handle($request, static fn (): Response => response('Origin recovered', 200, ['Content-Type' => 'text/html']));

        expect($response->getStatusCode())->toBe(200)
            ->and($response->getContent())->toBe('Origin recovered');
        Sleep::assertNeverSlept();
    } finally {
        $lock->release();
    }
});

it('reports unavailable render locks and renders normally', function (string $failure): void {
    SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    Exceptions::fake();
    $request = Request::create('https://example.test/unavailable-lock');
    app()->instance('request', $request);
    $renderLockPath = resolve(HtmlCachePublicationGuard::class)->lockPath() . '.render-' . hash('sha256', $request->fullUrl());

    if ($failure === 'path exception') {
        config()->set('capell-html-cache.deployment.shared_page_cache', true);
        config()->set('capell-html-cache.deployment.publication_lock_path', null);
    } else {
        File::ensureDirectoryExists($renderLockPath);
    }

    // Match Laravel's conversion of filesystem warnings into reportable errors.
    set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    try {
        $response = resolve(HtmlCacheMiddleware::class)->handle($request, static fn (): Response => response('Origin remains available', 200, ['Content-Type' => 'text/html']));

        expect($response->getStatusCode())->toBe(200)
            ->and($response->getContent())->toBe('Origin remains available')
            ->and($response->headers->has('Retry-After'))->toBeFalse();
        if ($failure === 'path exception') {
            Exceptions::assertReported(static fn (RuntimeException $exception): bool => str_contains($exception->getMessage(), 'requires a shared publication_lock_path'));
        } else {
            Exceptions::assertReported(static fn (ErrorException $exception): bool => str_contains($exception->getMessage(), 'fopen('));
        }
        Sleep::assertNeverSlept();
    } finally {
        restore_error_handler();

        if ($failure === 'open failure') {
            File::deleteDirectory($renderLockPath);
        }
    }
})->with(['path exception', 'open failure']);
