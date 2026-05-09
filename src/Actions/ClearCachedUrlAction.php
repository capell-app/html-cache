<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Core\Actions\LoadSiteDomainFromUrlAction;
use Capell\Core\Actions\VisitUrlAction;
use Capell\Core\Models\SiteDomain;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Capell\HtmlCache\Support\Cache\HtmlCacheStore;
use Lorisleiva\Actions\Concerns\AsJob;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static bool run(string $url, ?SiteDomain $siteDomain = null, bool $refresh = false)
 */
final class ClearCachedUrlAction
{
    use AsJob;
    use AsObject;

    public function handle(string $url, ?SiteDomain $siteDomain = null, bool $refresh = false): bool
    {
        $path = resolve(HtmlCachePathResolver::class)->normalizePathFromUrl($url);

        if (! $siteDomain instanceof SiteDomain) {
            $resolved = LoadSiteDomainFromUrlAction::run($url);

            if (is_array($resolved) && ($resolved[0] ?? null) instanceof SiteDomain) {
                $siteDomain = $resolved[0];
                $path = is_string($resolved[1] ?? null) ? $resolved[1] : $path;
            }
        }

        if (! $siteDomain instanceof SiteDomain || $siteDomain->domain === null || $siteDomain->domain === '') {
            return false;
        }

        $pathResolver = resolve(HtmlCachePathResolver::class);
        $store = resolve(HtmlCacheStore::class);

        $store->delete($pathResolver->pathForUrl($path, $siteDomain));
        $store->delete($pathResolver->pathForUrl($path, $siteDomain, error: true));

        CachedModelUrl::query()
            ->where('url_hash', CachedModelUrl::hashUrl($url))
            ->delete();

        if ($refresh) {
            VisitUrlAction::dispatch($url);
        }

        return true;
    }
}
