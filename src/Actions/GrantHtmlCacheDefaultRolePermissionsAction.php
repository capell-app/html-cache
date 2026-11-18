<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\HtmlCache\Enums\HtmlCachePermission;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Gives Capell's built-in editor and admin roles the HTML cache view and clear
 * permissions. Site access still limits which pages they can clear. Maintenance
 * stays with global administrators.
 *
 * Runs once from a migration, so it reaches existing installs and never
 * re-grants a permission an operator later withdrew. Roles that do not exist
 * yet are skipped; it never creates roles.
 */
final class GrantHtmlCacheDefaultRolePermissionsAction
{
    use AsFake;
    use AsObject;

    /**
     * Keys in `capell.roles`, so renamed built-in roles are honoured and an
     * unrelated custom role that happens to be called `admin` is not.
     *
     * @var list<string>
     */
    public const array DEFAULT_ROLE_KEYS = ['editor', 'admin'];

    public function handle(?string $guardName = null): void
    {
        $guard = $guardName ?? config('auth.defaults.guard', 'web');

        EnsureHtmlCachePermissionsAction::run($guard);

        $permissions = [
            HtmlCachePermission::ViewCachedModelUrls->value,
            HtmlCachePermission::ClearCachedModelUrls->value,
        ];

        Role::query()
            ->where('guard_name', $guard)
            ->whereIn('name', $this->roleNames())
            ->with('permissions')
            ->get()
            ->each(function (Role $role) use ($permissions): void {
                $role->givePermissionTo($permissions);
            });

        resolve(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return list<string>
     */
    private function roleNames(): array
    {
        $names = [];

        foreach (self::DEFAULT_ROLE_KEYS as $key) {
            $name = config('capell.roles.' . $key, $key);

            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }
}
