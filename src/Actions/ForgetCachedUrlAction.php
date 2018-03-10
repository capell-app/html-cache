<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Core\Actions\LoadSiteDomainFromUrlAction;
use Capell\Core\Models\SiteDomain;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Capell\HtmlCache\Support\Cache\HtmlCacheStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static bool run(Request $request, HtmlCacheStore $store)
 */
final class ForgetCachedUrlAction
{
    use AsFake;
    use AsObject;

    public function handle(Request $request, HtmlCacheStore $store): bool
    {
        if (! resolve(HtmlCachePathResolver::class)->hasSafeKey($request)) {
            return false;
        }
        $resolved = Schema::hasTable((new SiteDomain)->getTable()) ? LoadSiteDomainFromUrlAction::run($request->fullUrl()) : null;
        $domain = is_array($resolved) ? $resolved[0] : null;
        $hasIndex = Schema::hasTable((new CachedModelUrl)->getTable());
        $rows = CachedModelUrl::query()->where('url_hash', CachedModelUrl::hashUrl($request->fullUrl()))
            ->where('site_id', $domain?->site_id)->where('site_domain_id', $domain?->id);
        $storedPaths = $hasIndex ? $rows->get()->map(static fn (CachedModelUrl $row): string => $row->path)->all() : [];

        // The shared store owns the publication lock. Taking it here as well
        // would deadlock when the deletion Action opens its own lock handle.
        $deleted = DeleteCachedUrlArtefactsAction::run(
            $request,
            $domain,
            storedPaths: array_values($storedPaths),
            includeVariants: true,
            store: $store,
        );
        if ($hasIndex) {
            $rows->delete();
        }

        return $deleted;
    }
}
