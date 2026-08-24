<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Maintenance;

use Capell\Admin\Support\SiteScope;
use Capell\Core\Models\Site;
use Capell\HtmlCache\Enums\HtmlCachePermission;
use Illuminate\Contracts\Auth\Authenticatable;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Single source of truth for who may read and write maintenance cache state.
 * Both the Filament page (for what to render) and the typed Actions (for
 * what to actually allow) call through here, so a hidden button and a denied
 * write always agree.
 */
final class MaintenanceCachePermissions
{
    public static function canManage(?Authenticatable $actor): bool
    {
        if (! $actor instanceof Authenticatable) {
            return false;
        }

        if (SiteScope::isGlobalActor($actor)) {
            return true;
        }

        if (! method_exists($actor, 'hasPermissionTo')) {
            return false;
        }

        try {
            return $actor->hasPermissionTo(HtmlCachePermission::ManageMaintenance->value) === true;
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }

    public static function canManageGlobal(?Authenticatable $actor): bool
    {
        return $actor instanceof Authenticatable
            && SiteScope::isGlobalActor($actor)
            && self::canManage($actor);
    }

    public static function canManageSite(?Authenticatable $actor, Site $site): bool
    {
        return self::canManage($actor) && SiteScope::actorCanUseSite($actor, $site);
    }
}
