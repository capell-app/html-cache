<?php

declare(strict_types=1);

use Capell\Core\Data\SiteSpec\SiteSpecPrivacyRepairData;
use Capell\Core\Events\SiteSpecPrivacyRepaired;
use Capell\Core\Models\SiteDomain;
use Capell\HtmlCache\Actions\MarkCachedUrlStaleAction;
use Capell\HtmlCache\Actions\PurgeEdgeCacheAction;
use Capell\HtmlCache\Contracts\CachePurger;
use Capell\HtmlCache\Data\EdgeCachePurgeData;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Models\StaleCachedUrl;
use Capell\HtmlCache\Support\Cache\HtmlCachePublicationGuard;
use Capell\HtmlCache\Support\Cache\HtmlCacheStore;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

uses(HtmlCacheTestCase::class);

/**
 * @param  list<int>  $siteIds
 * @param  list<string>  $domains
 */
function privacyRepairedEvent(array $siteIds, array $domains): SiteSpecPrivacyRepaired
{
    return new SiteSpecPrivacyRepaired(new SiteSpecPrivacyRepairData($siteIds, $domains));
}

beforeEach(function (): void {
    // The event ships with the matching Core release; skip until that Core is installed.
    if (! class_exists(SiteSpecPrivacyRepaired::class)) {
        test()->markTestSkipped('Requires the Core release that dispatches SiteSpecPrivacyRepaired.');
    }

    Storage::fake('page_cache');
    Queue::fake();
    config()->set('capell-html-cache.enabled', true);
    config()->set('capell-html-cache.write_enabled', true);
    config()->set('capell-html-cache.request_coalescing.enabled', false);
});

it('withdraws warm unindexed HTML synchronously with workers stopped and scheduled work pending', function (string $mode): void {
    config()->set('capell-html-cache.invalidation.mode', $mode);
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $url = 'https://example.test/private-import';
    $request = Request::create($url);
    app()->instance('request', $request);
    resolve(HtmlCacheMiddleware::class)->handle($request, fn (): Response => response('<main>Private imported content</main>', 200, ['Content-Type' => 'text/html']));

    $warmRequest = Request::create($url);
    app()->instance('request', $warmRequest);
    $hit = resolve(HtmlCacheMiddleware::class)->handle($warmRequest, fn () => throw new RuntimeException('A warm hit must not render.'));
    expect($hit->headers->get('X-Frontend-Cache'))->toBe('HIT')
        ->and($hit->getContent())->toContain('Private imported content')
        ->and(CachedModelUrl::query()->count())->toBe(0);

    if ($mode === 'scheduled') {
        expect(MarkCachedUrlStaleAction::run($url))->toBe(1);
        expect(StaleCachedUrl::query()->where('status', StaleCachedUrl::STATUS_PENDING)->count())->toBe(1);
    }

    // These files have no dependency index or current domain mapping to identify them.
    Storage::disk('page_cache')->put('https.old-alias.test/private-import.html', 'Private alias HTML');
    Storage::disk('page_cache')->put('~port-8443/https.example.test/private-import.404.html', 'Private port HTML');
    Storage::disk('page_cache')->put('https.example.test/private-import~0123456789abcdef.html.fragments.json', 'Private fragment metadata');
    $purger = Mockery::mock(CachePurger::class);
    $purger->shouldReceive('purge')->once()->withArgs(function (EdgeCachePurgeData $purge): bool {
        expect(Storage::disk('page_cache')->allFiles())->toBeEmpty();

        return $purge->purgeAll;
    })->andReturnTrue();
    app()->instance(CachePurger::class, $purger);

    event(privacyRepairedEvent(
        siteIds: [$domain->site_id],
        domains: [$domain->domain],
    ));

    expect(Storage::disk('page_cache')->allFiles())->toBeEmpty();
    PurgeEdgeCacheAction::assertNotPushed();
    $freshRequest = Request::create($url);
    app()->instance('request', $freshRequest);
    $fresh = resolve(HtmlCacheMiddleware::class)->handle($freshRequest, fn (): Response => response('<main>Coming soon</main>', 200, ['Content-Type' => 'text/html']));
    expect($fresh->headers->get('X-Frontend-Cache'))->toBe('MISS')
        ->and($fresh->getContent())->toContain('Coming soon')->not->toContain('Private imported content');
})->with(['instant', 'scheduled']);

it('fences a render that started before privacy repair even when no files exist yet', function (): void {
    $domain = SiteDomain::factory()->create();
    $guard = resolve(HtmlCachePublicationGuard::class);
    $request = Request::create('https://example.test/private-import');
    $token = $guard->capture($request);
    expect($token)->not->toBeNull();

    event(privacyRepairedEvent(
        siteIds: [$domain->site_id],
        domains: [$domain->domain],
    ));

    expect($guard->publish($token, function (): bool {
        Storage::disk('page_cache')->put('https.example.test/private-import.html', 'Private in-flight HTML');

        return true;
    }))->toBeFalse();
    expect(Storage::disk('page_cache')->allFiles())->toBeEmpty();
    PurgeEdgeCacheAction::assertNotPushed();
});

it('propagates failed origin deletion and clears it on a subsequent repair attempt', function (): void {
    $domain = SiteDomain::factory()->create();
    $storage = Storage::disk('page_cache');
    $storage->put('https.example.test/private-import.html', 'Private HTML');

    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('path')->andReturnUsing(fn (string $path): string => $storage->path($path));
    $disk->shouldReceive('directories')->andReturn(['https.example.test']);
    $disk->shouldReceive('files')->andReturn([]);
    $disk->shouldReceive('deleteDirectory')->with('https.example.test')->once()->andReturnFalse();
    $disk->shouldReceive('deleteDirectory')->with('https.example.test')->once()->andReturnUsing(fn (string $path): bool => $storage->deleteDirectory($path));
    $manager = Mockery::mock(FilesystemManager::class);
    $manager->shouldReceive('disk')->with('page_cache')->andReturn($disk);
    app()->instance(HtmlCacheStore::class, new HtmlCacheStore($manager));
    $purger = Mockery::mock(CachePurger::class);
    $purger->shouldReceive('purge')->once()->withArgs(fn (EdgeCachePurgeData $purge): bool => $purge->purgeAll)->andReturnTrue();
    app()->instance(CachePurger::class, $purger);
    $event = privacyRepairedEvent(
        siteIds: [$domain->site_id],
        domains: [$domain->domain],
    );

    expect(fn () => event($event))->toThrow(RuntimeException::class, 'Unable to clear every HTML cache artefact');
    expect($storage->get('https.example.test/private-import.html'))->toBe('Private HTML');

    $purger->shouldNotHaveReceived('purge');

    event($event);

    expect($storage->allFiles())->toBeEmpty();
    PurgeEdgeCacheAction::assertNotPushed();
});

it('propagates an edge purge failure and awaits the purge again on retry after origin files are gone', function (bool $throws): void {
    $domain = SiteDomain::factory()->create();
    Storage::disk('page_cache')->put('https.example.test/private-import.html', 'Private HTML');
    $purger = Mockery::mock(CachePurger::class);
    $first = $purger->shouldReceive('purge')->once()->withArgs(fn (EdgeCachePurgeData $purge): bool => $purge->purgeAll);
    if ($throws) {
        $first->andThrow(new RuntimeException('Edge unavailable.'));
    } else {
        $first->andReturnFalse();
    }

    $purger->shouldReceive('purge')->once()->withArgs(fn (EdgeCachePurgeData $purge): bool => $purge->purgeAll)->andReturnTrue();
    app()->instance(CachePurger::class, $purger);
    $event = privacyRepairedEvent(
        siteIds: [$domain->site_id],
        domains: [$domain->domain],
    );

    expect(fn () => event($event))->toThrow(RuntimeException::class, $throws ? 'Edge unavailable.' : 'Unable to purge the edge HTML cache.');
    expect(Storage::disk('page_cache')->allFiles())->toBeEmpty();

    event($event);

    PurgeEdgeCacheAction::assertNotPushed();
})->with([false, true]);
