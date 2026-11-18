<?php

declare(strict_types=1);

use Capell\HtmlCache\Actions\GrantHtmlCacheDefaultRolePermissionsAction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = config('permission.table_names');

        if (! is_array($tables)) {
            return;
        }

        foreach (['permissions', 'roles', 'role_has_permissions'] as $key) {
            $table = $tables[$key] ?? null;

            if (! is_string($table) || ! Schema::hasTable($table)) {
                return;
            }
        }

        GrantHtmlCacheDefaultRolePermissionsAction::run();
    }

    public function down(): void
    {
        // Grants are left in place: an operator may rely on them after upgrading.
    }
};
