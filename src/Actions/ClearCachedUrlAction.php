<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Core\Actions\LoadSiteDomainFromUrlAction;
use Capell\Core\Actions\VisitUrlAction;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Language;
use Capell\Core\Models\SiteDomain;
use Capell\Frontend\Support\Cache\SurrogateKeyNormalizer;
use Capell\HtmlCache\Contracts\CachePurger;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Capell\HtmlCache\Support\Cache\HtmlCacheStore;
use Illuminate\Database\Eloquent\Collection;
use Lorisleiva\Actions\Concerns\AsJob;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static bool run(string|CachedModelUrl $url, ?SiteDomain $siteDomain = null, bool $refresh = false)
 */
final class ClearCachedUrlAction
{
    use AsJob;
    use AsObject;

    public function handle(string|CachedModelUrl $url, ?SiteDomain $siteDomain = null, bool $refresh = false): bool
    {
        if ($url instanceof CachedModelUrl) {
            $selectedCachedModelUrl = $url;
            $urlString = $url->url;
            $path = $url->path;
        } else {
            $selectedCachedModelUrl = null;
            $urlString = $url;
            $path = null;
        }

        $selectedCachedModelUrl?->loadMissing('siteDomain', 'language');
        $path ??= resolve(HtmlCachePathResolver::class)->normalizePathFromUrl($urlString);
        $cachedModelUrls = $this->cachedModelUrls($urlString, $selectedCachedModelUrl);
        $siteDomain ??= $selectedCachedModelUrl?->siteDomain;

        if (! $siteDomain instanceof SiteDomain) {
            $resolved = LoadSiteDomainFromUrlAction::run($urlString);

            if (is_array($resolved) && ($resolved[0] ?? null) instanceof SiteDomain) {
                $siteDomain = $resolved[0];
                $path = is_string($resolved[1] ?? null) ? $resolved[1] : $path;
            }
        }

        if (! $siteDomain instanceof SiteDomain || $siteDomain->domain === null || $siteDomain->domain === '') {
            $this->deleteFilesFromCachedRows($cachedModelUrls);
            $this->purgeSurrogateKeys($cachedModelUrls);
            $cachedModelUrls->each->delete();

            return false;
        }

        $this->deleteFilesFromCachedRows($cachedModelUrls);
        $this->purgeSurrogateKeys($cachedModelUrls);

        $pathResolver = resolve(HtmlCachePathResolver::class);
        $store = resolve(HtmlCacheStore::class);
        $store->delete($pathResolver->pathForUrl($path, $siteDomain));
        $store->delete($pathResolver->pathForUrl($path, $siteDomain, error: true));

        $cachedModelUrls->each->delete();

        if ($refresh) {
            VisitUrlAction::dispatch($urlString);
        }

        return true;
    }

    /**
     * @return Collection<int, CachedModelUrl>
     */
    private function cachedModelUrls(string $url, ?CachedModelUrl $selectedCachedModelUrl): Collection
    {
        $query = CachedModelUrl::query()
            ->with('siteDomain', 'language')
            ->where('url_hash', CachedModelUrl::hashUrl($url));

        if (! $selectedCachedModelUrl instanceof CachedModelUrl) {
            return $query->get();
        }

        if ($selectedCachedModelUrl->site_id !== null) {
            return $query
                ->where('site_id', $selectedCachedModelUrl->site_id)
                ->get();
        }

        return $query
            ->whereKey($selectedCachedModelUrl->getKey())
            ->get();
    }

    /**
     * @param  Collection<int, CachedModelUrl>  $cachedModelUrls
     */
    private function deleteFilesFromCachedRows(Collection $cachedModelUrls): void
    {
        $pathResolver = resolve(HtmlCachePathResolver::class);
        $store = resolve(HtmlCacheStore::class);
        $files = [];

        foreach ($cachedModelUrls as $cachedModelUrl) {
            if (! $cachedModelUrl->siteDomain instanceof SiteDomain) {
                continue;
            }

            $files[] = $pathResolver->pathForUrl($cachedModelUrl->path, $cachedModelUrl->siteDomain);
            $files[] = $pathResolver->pathForUrl($cachedModelUrl->path, $cachedModelUrl->siteDomain, error: true);
        }

        foreach (array_unique($files) as $file) {
            $store->delete($file);
        }
    }

    /**
     * @param  Collection<int, CachedModelUrl>  $cachedModelUrls
     */
    private function purgeSurrogateKeys(Collection $cachedModelUrls): void
    {
        $keys = [];

        foreach ($cachedModelUrls as $cachedModelUrl) {
            if ($cachedModelUrl->site_id !== null) {
                $keys[] = 'site-' . $cachedModelUrl->site_id;
            }

            if ($cachedModelUrl->language instanceof Language) {
                $keys[] = 'lang-' . $cachedModelUrl->language->code;
            }

            if (is_a($cachedModelUrl->cacheable_type, Pageable::class, true)) {
                $keys[] = 'page-' . $cachedModelUrl->cacheable_id;
            }
        }

        $keys = SurrogateKeyNormalizer::normalize($keys);

        if ($keys !== []) {
            resolve(CachePurger::class)->purge($keys);
        }
    }
}
