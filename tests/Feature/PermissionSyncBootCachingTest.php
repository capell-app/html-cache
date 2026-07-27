<?php

declare(strict_types=1);

use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\HtmlCache\Enums\HtmlCachePermission;
use Capell\HtmlCache\Providers\HtmlCacheServiceProvider;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

uses(HtmlCacheTestCase::class);

/**
 * Regression coverage for the packageBooted() permission-sync fix: syncing
 * used to run EnsureHtmlCachePermissionsAction (a permission SELECT/
 * firstOrCreate query per HtmlCachePermission case, plus a
 * forgetCachedPermissions() cache bust) unconditionally on every single
 * request. That is now gated behind a cached "already synced" marker so the
 * DB work only happens once per marker window, while still self-healing if
 * permissions are ever missing.
 *
 * EnsureHtmlCachePermissionsAction is `final`, so it cannot be Mockery-mocked
 * (repo convention keeps Actions final); the queries-eliminated claim is
 * instead proven directly via the query log, which is also the more honest
 * metric for a performance fix.
 */
function callHtmlCacheProviderEnsurePermissions(): void
{
    $provider = app()->getProvider(HtmlCacheServiceProvider::class);

    if (! $provider instanceof HtmlCacheServiceProvider) {
        throw new RuntimeException('HtmlCacheServiceProvider was not registered in the test application.');
    }

    $ensurePermissions = new ReflectionMethod($provider, 'ensurePermissions');
    $ensurePermissions->setAccessible(true);
    $ensurePermissions->invoke($provider);
}

it('syncs permissions, runs queries, and sets the cache marker the first time permissions are missing', function (): void {
    resolve(RuntimeSchemaState::class)->refreshTable('permissions');
    Cache::forget('capell-html-cache.permissions-synced');

    expect(Cache::get('capell-html-cache.permissions-synced'))->toBeNull();

    $queryCount = 0;
    DB::listen(function () use (&$queryCount): void {
        $queryCount++;
    });

    callHtmlCacheProviderEnsurePermissions();

    expect($queryCount)->toBeGreaterThan(0)
        ->and(Cache::get('capell-html-cache.permissions-synced'))->toBeTrue();

    foreach (HtmlCachePermission::cases() as $permission) {
        expect(Permission::query()->where('name', $permission->value)->exists())->toBeTrue();
    }
});

it('runs no queries for permission sync once the cache marker is already set', function (): void {
    resolve(RuntimeSchemaState::class)->refreshTable('permissions');
    Cache::put('capell-html-cache.permissions-synced', true, 3600);

    $queryCount = 0;
    DB::listen(function () use (&$queryCount): void {
        $queryCount++;
    });

    callHtmlCacheProviderEnsurePermissions();

    expect($queryCount)->toBe(0);
});
