<?php

declare(strict_types=1);

use Capell\Core\Actions\RuntimeRefresh\RefreshInstalledPackageRuntimeAction;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\HtmlCache\Enums\HtmlCachePermission;
use Capell\HtmlCache\Providers\HtmlCacheServiceProvider;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Capell\Tests\Support\InstalledRuntimeParity;
use Capell\Tests\Support\InstalledRuntimeState;
use Capell\Tests\Support\InstalledRuntimeTestCase;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

uses(HtmlCacheTestCase::class);

/** @param Closure(int): void $assertPermissions */
function activateHtmlCacheWithPermissionMarker(bool $synced, Closure $assertPermissions): void
{
    InstalledRuntimeState::run(function () use ($synced, $assertPermissions): void {
        $application = InstalledRuntimeTestCase::boot(InstalledRuntimeParity::manifest('html-cache'), false, sourceRoot: dirname(__DIR__, 2));
        InstalledRuntimeTestCase::prepareLateInstallMigrations($application);
        resolve(RuntimeSchemaState::class)->refreshTable('permissions');
        Cache::forget('capell-html-cache.permissions-synced');
        if ($synced) {
            Cache::put('capell-html-cache.permissions-synced', true, 3600);
        }

        $queryCount = 0;
        DB::listen(function (QueryExecuted $query) use (&$queryCount): void {
            if (str_contains($query->sql, '"permissions"')) {
                $queryCount++;
            }
        });

        CapellCore::markPackageInstalled(HtmlCacheServiceProvider::$packageName);
        RefreshInstalledPackageRuntimeAction::run(CapellCore::getPackage(HtmlCacheServiceProvider::$packageName));
        $assertPermissions($queryCount);
    });
}

it('creates missing permissions and records the sync marker when the package becomes available', function (): void {
    activateHtmlCacheWithPermissionMarker(false, function (int $queryCount): void {
        expect($queryCount)->toBeGreaterThan(0)
            ->and(Cache::get('capell-html-cache.permissions-synced'))->toBeTrue();

        foreach (HtmlCachePermission::cases() as $permission) {
            expect(Permission::query()->where('name', $permission->value)->exists())->toBeTrue();
        }
    });
});

it('avoids permission database work when activation sees an existing sync marker', function (): void {
    activateHtmlCacheWithPermissionMarker(true, function (int $queryCount): void {
        expect($queryCount)->toBe(0)
            ->and(Cache::get('capell-html-cache.permissions-synced'))->toBeTrue();
    });
});
