<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\HtmlCache\Enums\HtmlCachePermission;
use Lorisleiva\Actions\Concerns\AsObject;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

final class EnsureHtmlCachePermissionsAction
{
    use AsObject;

    public function handle(?string $guardName = null): void
    {
        $guard = $guardName ?? config('auth.defaults.guard', 'web');

        foreach (HtmlCachePermission::cases() as $permission) {
            Permission::query()->firstOrCreate([
                'name' => $permission->value,
                'guard_name' => $guard,
            ]);
        }

        resolve(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
