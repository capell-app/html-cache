<?php

declare(strict_types=1);

use Capell\HtmlCache\Actions\AssertHtmlCacheInvalidationTopologyAction;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;

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

it('accepts a configured cross-node purge path', function (string $driver): void {
    config([
        'capell.multi_node' => true,
        'capell-html-cache.purge.driver' => $driver,
    ]);

    AssertHtmlCacheInvalidationTopologyAction::run();

    expect(true)->toBeTrue();
})->with(['cloudflare', 'http']);
