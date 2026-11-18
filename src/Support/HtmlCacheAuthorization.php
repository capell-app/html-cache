<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support;

use Capell\Admin\Support\SiteScope;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\Permissions\PermissionTeamContext;
use Capell\HtmlCache\Enums\HtmlCachePermission;
use Capell\HtmlCache\Models\CachedModelUrl;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Single source of truth for who may see and clear stored HTML from the admin.
 * Every admin entry point (table columns and actions, Livewire listeners and
 * the queued job they dispatch) calls through here, so a hidden control and a
 * refused request always agree. Site checks run inside that site's permission
 * team, so they do not depend on whichever site the admin last selected.
 */
final class HtmlCacheAuthorization
{
    public static function canView(?Authenticatable $actor): bool
    {
        if (! $actor instanceof Authenticatable) {
            return false;
        }

        return SiteScope::isGlobalActor($actor)
            || self::holds($actor, HtmlCachePermission::ViewCachedModelUrls);
    }

    public static function canClear(?Authenticatable $actor): bool
    {
        if (! $actor instanceof Authenticatable) {
            return false;
        }

        return SiteScope::isGlobalActor($actor)
            || self::holds($actor, HtmlCachePermission::ClearCachedModelUrls);
    }

    /**
     * Clearing every stored page reaches every site, so only a global actor may do it.
     */
    public static function canClearAll(?Authenticatable $actor): bool
    {
        return $actor instanceof Authenticatable && SiteScope::isGlobalActor($actor);
    }

    public static function canClearForSite(?Authenticatable $actor, ?Site $site): bool
    {
        if (! $actor instanceof Authenticatable) {
            return false;
        }

        if (SiteScope::isGlobalActor($actor)) {
            return true;
        }

        if (! $site instanceof Site) {
            return false;
        }

        return resolve(HtmlCacheAuthorizationMemo::class)->remember(
            $actor,
            $site->id,
            fn (): bool => PermissionTeamContext::run(
                $site->id,
                fn (): bool => self::holds($actor, HtmlCachePermission::ClearCachedModelUrls)
                    && SiteScope::actorCanUseSite($actor, $site),
                $actor instanceof Model ? $actor : null,
            ),
        );
    }

    /**
     * A stored row is cleared at its own site and domain. A site-scoped actor
     * may clear it only when that domain belongs to the row's site, so a row
     * without a domain cannot be used to reach a domain on another site.
     */
    public static function canClearCachedUrl(?Authenticatable $actor, CachedModelUrl $cachedUrl): bool
    {
        if ($actor instanceof Authenticatable && SiteScope::isGlobalActor($actor)) {
            return true;
        }

        $site = $cachedUrl->relationLoaded('site') ? $cachedUrl->site : $cachedUrl->site()->first();
        $siteDomain = $cachedUrl->relationLoaded('siteDomain') ? $cachedUrl->siteDomain : $cachedUrl->siteDomain()->first();

        if (! $site instanceof Site || ! $siteDomain instanceof SiteDomain || $siteDomain->site_id !== $site->id) {
            return false;
        }

        return self::canClearForSite($actor, $site);
    }

    /**
     * A page may have addresses on several sites; the actor must be allowed to
     * clear every one of them.
     */
    public static function canClearPage(?Authenticatable $actor, Pageable|PageUrl $record): bool
    {
        if (! $actor instanceof Authenticatable) {
            return false;
        }

        if (SiteScope::isGlobalActor($actor)) {
            return true;
        }

        $pageUrls = match (true) {
            $record instanceof PageUrl => collect([$record]),
            $record->relationLoaded('pageUrls') => $record->pageUrls,
            default => $record->pageUrls()->get(),
        };

        if ($pageUrls->isEmpty()) {
            return self::canClear($actor);
        }

        foreach ($pageUrls as $pageUrl) {
            if (! $pageUrl instanceof PageUrl) {
                return false;
            }

            $site = $pageUrl->relationLoaded('site') ? $pageUrl->site : $pageUrl->site()->first();

            if (! self::canClearForSite($actor, $site instanceof Site ? $site : null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Each requested address must be a stored page on a site the actor may clear.
     *
     * @param  array<int|string, mixed>  $urls
     */
    public static function canClearUrls(?Authenticatable $actor, array $urls): bool
    {
        return self::clearableUrls($actor, $urls) !== null;
    }

    /**
     * Resolves what the actor may clear for the requested addresses, or null
     * when any of them is refused. A site-scoped actor gets the stored rows, so
     * each clear stays pinned to that row's site and domain; every row sharing
     * an address must be one the actor may clear. A global actor may clear any
     * address, including one that is not in the cache map.
     *
     * @param  array<int|string, mixed>  $urls
     * @return list<CachedModelUrl|string>|null
     */
    public static function clearableUrls(?Authenticatable $actor, array $urls): ?array
    {
        if (! $actor instanceof Authenticatable || $urls === []) {
            return null;
        }

        $global = SiteScope::isGlobalActor($actor);
        $targets = [];

        foreach ($urls as $url) {
            if (! is_string($url)) {
                return null;
            }

            if ($global) {
                $targets[] = $url;

                continue;
            }

            $rows = CachedModelUrl::query()
                ->with(['site', 'siteDomain'])
                ->where('url_hash', CachedModelUrl::hashUrl($url))
                ->get();

            if ($rows->isEmpty()) {
                return null;
            }

            foreach ($rows as $row) {
                if (! self::canClearCachedUrl($actor, $row)) {
                    return null;
                }

                $targets[] = $row;
            }
        }

        return $targets;
    }

    private static function holds(Authenticatable $actor, HtmlCachePermission $permission): bool
    {
        if (! method_exists($actor, 'hasPermissionTo')) {
            return false;
        }

        try {
            return $actor->hasPermissionTo($permission->value) === true;
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }
}
