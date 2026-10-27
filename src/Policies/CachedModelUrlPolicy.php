<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Policies;

use Capell\Admin\Support\SiteScope;
use Capell\Core\Models\Site;
use Capell\HtmlCache\Enums\HtmlCachePermission;
use Capell\HtmlCache\Models\CachedModelUrl;
use Illuminate\Contracts\Auth\Authenticatable;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

final class CachedModelUrlPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        if (SiteScope::isGlobalActor($user)) {
            return true;
        }

        try {
            return $user->checkPermissionTo(HtmlCachePermission::ViewCachedModelUrls->value);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }

    public function view(Authenticatable $user, CachedModelUrl $url): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        if (SiteScope::isGlobalActor($user)) {
            return true;
        }

        $site = $url->site;

        return $site instanceof Site && SiteScope::actorCanUseSite($user, $site);
    }

    public function create(Authenticatable $user): bool
    {
        return false;
    }

    public function update(Authenticatable $user, CachedModelUrl $url): bool
    {
        return false;
    }

    public function delete(Authenticatable $user, CachedModelUrl $url): bool
    {
        return false;
    }

    public function deleteAny(Authenticatable $user): bool
    {
        return false;
    }
}
