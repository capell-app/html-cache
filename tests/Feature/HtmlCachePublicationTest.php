<?php

declare(strict_types=1);

use Capell\Core\Models\SiteDomain;
use Capell\HtmlCache\Actions\ClearCachedUrlAction;
use Capell\HtmlCache\Actions\RefreshCachedUrlAtomicallyAction;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Models\StaleCachedUrl;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Capell\HtmlCache\Support\Cache\HtmlCachePublicationGuard;
use Capell\HtmlCache\Support\Cache\HtmlCacheStore;
use Capell\HtmlCache\Support\Cache\PageCache;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(HtmlCacheTestCase::class);

beforeEach(function (): void {
    Storage::fake('page_cache');
    Queue::fake();
    config()->set('capell-html-cache.enabled', true);
    config()->set('capell-html-cache.write_enabled', true);
    config()->set('capell-html-cache.request_coalescing.enabled', false);
});

it('does not republish a render started before hard invalidation', function (string $operation, bool $trustedWarm): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $url = 'https://example.test/withdrawn';
    $path = resolve(HtmlCachePathResolver::class)->pathForUrl('/withdrawn', $domain);
    $request = Request::create($url);
    $request->attributes->set(HtmlCacheMiddleware::BYPASS_CACHE_READ_ATTRIBUTE, $trustedWarm);
    app()->instance('request', $request);

    $response = resolve(HtmlCacheMiddleware::class)->handle($request, function () use ($operation, $url, $path) {
        match ($operation) {
            'url' => ClearCachedUrlAction::run($url),
            'all' => resolve(HtmlCacheStore::class)->deleteAll(),
            'directory' => resolve(HtmlCacheStore::class)->deleteDirectory(dirname($path)),
            'forget' => resolve(PageCache::class)->forget(Str::beforeLast($path, '.html')),
            'clear' => resolve(PageCache::class)->clear(),
            default => throw new LogicException('Unknown invalidation operation: ' . $operation),
        };

        return response('<main>Withdrawn during this render</main>', 200, ['Content-Type' => 'text/html']);
    });

    expect(Storage::disk('page_cache')->exists($path))->toBeFalse()
        ->and($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Cache-Control'))->toContain('private', 'no-store')
        ->and($request->attributes->get(HtmlCacheMiddleware::CACHE_WRITE_SUCCEEDED_ATTRIBUTE))->toBeFalse();

    $fresh = Request::create($url);
    app()->instance('request', $fresh);
    resolve(HtmlCacheMiddleware::class)->handle($fresh, fn () => response('<main>Current content</main>', 200, ['Content-Type' => 'text/html']));
    expect(Storage::disk('page_cache')->get($path))->toContain('Current content');
})->with(['url', 'all', 'directory', 'forget', 'clear'])->with([false, true]);

it('rejects a direct writer without a token captured before rendering', function (): void {
    SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $request = Request::create('https://example.test/unfenced');
    app()->instance('request', $request);

    $published = resolve(PageCache::class)->cache($request, response('Unfenced content', 200, ['Content-Type' => 'text/html']));

    expect($published)->toBeFalse()
        ->and($request->attributes->get(HtmlCachePublicationGuard::REJECTED_ATTRIBUTE))->toBeTrue()
        ->and(Storage::disk('page_cache')->allFiles())->toBeEmpty();
});

it('makes an already-public response private when publication fails after rendering', function (): void {
    SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $lockPath = storage_path('framework/testing/html-cache-publication-' . Str::uuid()->toString() . '.lock');
    config()->set('capell-html-cache.deployment.publication_lock_path', $lockPath);
    $request = Request::create('https://example.test/late-failure');
    app()->instance('request', $request);

    try {
        $response = resolve(HtmlCacheMiddleware::class)->handle($request, function () use ($lockPath) {
            File::put($lockPath, 'unreadable generation');

            return response('Readable uncached content', 200, [
                'Content-Type' => 'text/html',
                'Cache-Control' => 'public, max-age=300, s-maxage=300',
            ]);
        });

        expect($response->getStatusCode())->toBe(200)
            ->and($response->getContent())->toBe('Readable uncached content')
            ->and($response->headers->get('Cache-Control'))->toContain('private', 'no-store')
            ->and($request->attributes->get(HtmlCacheMiddleware::PRIVATE_RESPONSE_ATTRIBUTE))->toBeTrue()
            ->and($request->attributes->get(HtmlCacheMiddleware::CACHE_WRITE_SUCCEEDED_ATTRIBUTE))->toBeFalse()
            ->and(Storage::disk('page_cache')->allFiles())->toBeEmpty();
    } finally {
        File::delete($lockPath);
    }
});

it('does not retry a failed publication-token capture after rendering', function (): void {
    $request = Request::create('https://example.test/unavailable');
    app()->instance('request', $request);
    $request->attributes->set(HtmlCachePublicationGuard::REQUEST_TOKEN_ATTRIBUTE, null);

    $response = resolve(HtmlCacheMiddleware::class)->handle($request, fn () => response('Readable uncached content', 200, ['Content-Type' => 'text/html']));

    expect($response->getContent())->toBe('Readable uncached content')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(Storage::disk('page_cache')->allFiles())->toBeEmpty();
});

it('retains the pre-render token through the stale refresh fallback writer', function (): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $url = 'https://example.test/withdrawn';
    $path = resolve(HtmlCachePathResolver::class)->pathForUrl('/withdrawn', $domain);
    $stale = StaleCachedUrl::query()->create([
        'url' => $url,
        'url_hash' => CachedModelUrl::hashUrl($url),
        'path' => '/withdrawn',
        'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl($url), $domain->site_id, $domain->id, '/withdrawn'),
        'site_id' => $domain->site_id,
        'site_domain_id' => $domain->id,
        'language_id' => $domain->language_id,
        'cache_path' => $path,
        'error_cache_path' => resolve(HtmlCachePathResolver::class)->pathForUrl('/withdrawn', $domain, error: true),
        'reason' => 'regression',
        'status' => StaleCachedUrl::STATUS_PROCESSING,
        'claim_token' => Str::uuid()->toString(),
    ]);
    Route::get('/withdrawn', function () use ($url) {
        ClearCachedUrlAction::run($url);

        return response('Obsolete rendered content', 200, ['Content-Type' => 'text/html']);
    });

    expect(fn () => RefreshCachedUrlAtomicallyAction::run($stale, true))
        ->toThrow(RuntimeException::class, 'invalidated during rendering');
    expect(Storage::disk('page_cache')->exists($path))->toBeFalse()
        ->and(Storage::disk('page_cache')->exists($path . PageCache::FRAGMENT_METADATA_EXTENSION))->toBeFalse();
});

it('requires a shared publication lock when the page cache is shared between nodes', function (): void {
    config()->set('capell-html-cache.deployment.shared_page_cache', true);
    config()->set('capell-html-cache.deployment.publication_lock_path', null);

    expect(resolve(HtmlCachePublicationGuard::class)->snapshot())->toBeNull();
    expect(fn () => resolve(HtmlCacheStore::class)->deleteAll())
        ->toThrow(RuntimeException::class, 'requires a shared publication_lock_path');
});

it('rejects publication lock aliases inside the page cache before deleting content', function (string $alias): void {
    $root = rtrim(Storage::disk('page_cache')->path(''), '/');
    $outside = dirname($root) . '/publication-alias-' . Str::uuid()->toString();
    File::ensureDirectoryExists($outside);
    Storage::disk('page_cache')->put('current.html', 'Current public content');

    $path = match ($alias) {
        'traversal' => $outside . '/../' . basename($root) . '/missing/guard.lock',
        'directory symlink' => $outside . '/cache/missing/guard.lock',
        'file symlink', 'dangling symlink' => $outside . '/guard.lock',
        default => throw new LogicException('Unknown alias'),
    };

    if ($alias === 'directory symlink') {
        symlink($root, $outside . '/cache');
    } elseif (in_array($alias, ['file symlink', 'dangling symlink'], true)) {
        if ($alias === 'file symlink') {
            File::put($root . '/guard.lock', str_repeat('a', 64));
        }
        symlink($root . '/guard.lock', $path);
    }

    config()->set('capell-html-cache.deployment.publication_lock_path', $path);

    try {
        expect(fn () => resolve(HtmlCacheStore::class)->deleteAll())->toThrow(RuntimeException::class);
        expect(Storage::disk('page_cache')->get('current.html'))->toBe('Current public content');
    } finally {
        foreach ([$outside . '/cache', $outside . '/guard.lock'] as $link) {
            if (is_link($link)) {
                unlink($link);
            }
        }
        File::deleteDirectory($outside);
    }
})->with(['traversal', 'directory symlink', 'file symlink', 'dangling symlink']);
