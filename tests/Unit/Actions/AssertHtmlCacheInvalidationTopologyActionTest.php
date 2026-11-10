<?php

declare(strict_types=1);

use Capell\HtmlCache\Actions\AssertHtmlCacheInvalidationTopologyAction;
use Capell\HtmlCache\Providers\HtmlCacheServiceProvider;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

uses(HtmlCacheTestCase::class);

beforeEach(function (): void {
    config([
        'capell.multi_node' => false,
        'capell-html-cache.deployment.shared_page_cache' => false,
        'capell-html-cache.purge.driver' => 'null',
        'filesystems.disks.page_cache.driver' => 'local',
    ]);
});

it('allows node-local invalidation on a declared single-node installation', function (): void {
    AssertHtmlCacheInvalidationTopologyAction::run();

    expect(true)->toBeTrue();
});

it('refuses node-local invalidation on a multi-node installation', function (): void {
    config()->set('capell.multi_node', true);

    expect(function (): void {
        AssertHtmlCacheInvalidationTopologyAction::run();
    })
        ->toThrow(
            RuntimeException::class,
            'HTML page cache invalidation cannot run while CAPELL_MULTI_NODE=true because the [page_cache] filesystem disk uses the node-local [local] driver',
        );
});

it('refuses an unresolved page cache driver on a multi-node installation', function (): void {
    config([
        'capell.multi_node' => true,
        'filesystems.disks.page_cache.driver' => null,
    ]);

    expect(function (): void {
        AssertHtmlCacheInvalidationTopologyAction::run();
    })
        ->toThrow(
            RuntimeException::class,
            'HTML page cache invalidation cannot run while CAPELL_MULTI_NODE=true because the [page_cache] filesystem disk has no resolvable driver',
        );
});

it('accepts an explicitly shared page cache on a multi-node installation', function (): void {
    config([
        'capell.multi_node' => true,
        'capell-html-cache.deployment.shared_page_cache' => true,
    ]);

    AssertHtmlCacheInvalidationTopologyAction::run();

    expect(true)->toBeTrue();
});

it('refuses an edge purge driver over node-local storage because a clear leaves the peer origin stale', function (string $driver): void {
    // Two web nodes, each with its own page_cache directory, behind one CDN.
    $root = sys_get_temp_dir() . '/capell-html-cache-origins-' . bin2hex(random_bytes(6));
    $filesystem = new Filesystem;
    $filesystem->ensureDirectoryExists($root . '/node-a/example.test');
    $filesystem->ensureDirectoryExists($root . '/node-b/example.test');
    $filesystem->put($root . '/node-a/example.test/withdrawn.html', '<html>withdrawn</html>');
    $filesystem->put($root . '/node-b/example.test/withdrawn.html', '<html>withdrawn</html>');

    try {
        config([
            'capell.multi_node' => true,
            'capell-html-cache.purge.driver' => $driver,
            'filesystems.disks.page_cache' => ['driver' => 'local', 'root' => $root . '/node-a', 'throw' => false],
        ]);

        // The clear job runs on node A only: node B keeps serving the withdrawn page
        // once the edge purge empties the CDN, which is why the driver is no excuse.
        Storage::disk('page_cache')->deleteDirectory('example.test');

        expect($filesystem->exists($root . '/node-a/example.test/withdrawn.html'))->toBeFalse()
            ->and($filesystem->exists($root . '/node-b/example.test/withdrawn.html'))->toBeTrue()
            ->and(function (): void {
                AssertHtmlCacheInvalidationTopologyAction::run();
            })->toThrow(
                RuntimeException::class,
                'HTML page cache invalidation cannot run while CAPELL_MULTI_NODE=true because the [page_cache] filesystem disk uses the node-local [local] driver',
            );

        // Shared origin storage is what makes the topology safe; the edge driver then purges on top of it.
        config()->set('capell-html-cache.deployment.shared_page_cache', true);
        AssertHtmlCacheInvalidationTopologyAction::run();
    } finally {
        $filesystem->deleteDirectory($root);
    }
})->with(['cloudflare', 'http']);

it('aborts provider boot on an unsafe topology instead of leaving unpurgeable cached pages', function (): void {
    config()->set('capell.multi_node', true);

    $provider = $this->app->getProvider(HtmlCacheServiceProvider::class);

    expect($provider)->toBeInstanceOf(HtmlCacheServiceProvider::class);
    assert($provider instanceof HtmlCacheServiceProvider);

    expect(fn () => $provider->packageBooted())->toThrow(RuntimeException::class, 'CAPELL_MULTI_NODE=true');
});
