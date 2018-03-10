<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Core\Actions\LoadSiteDomainFromUrlAction;
use Capell\Core\Actions\VisitUrlAction;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Language;
use Capell\Core\Models\SiteDomain;
use Capell\Frontend\Support\Cache\SurrogateKeyNormalizer;
use Capell\HtmlCache\Data\EdgeCachePurgeData;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsJob;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static bool run(string|CachedModelUrl $url, ?SiteDomain $siteDomain = null, bool $refresh = false)
 */
final class ClearCachedUrlAction
{
    use AsFake;
    use AsJob;
    use AsObject;

    public function handle(string|CachedModelUrl $url, ?SiteDomain $siteDomain = null, bool $refresh = false): bool
    {
        $domainProvided = $siteDomain instanceof SiteDomain;
        if ($url instanceof CachedModelUrl) {
            if ($siteDomain instanceof SiteDomain
                && ($siteDomain->site_id !== $url->site_id || $siteDomain->id !== $url->site_domain_id)) {
                throw new InvalidArgumentException('The supplied site domain does not match the cached URL ownership.');
            }
            $selectedCachedModelUrl = $url;
            $urlString = $url->url;
        } else {
            $selectedCachedModelUrl = null;
            $urlString = $url;
        }

        $request = Request::create($urlString);
        if (! resolve(HtmlCachePathResolver::class)->hasSafeKey($request)) {
            return false;
        }

        $selectedCachedModelUrl?->load([
            'siteDomain' => static fn (BuilderContract $query): BuilderContract => $query->withTrashed(),
            'language',
        ]);
        $siteDomain = $selectedCachedModelUrl->siteDomain ?? $siteDomain;

        if (! $siteDomain instanceof SiteDomain) {
            $resolved = LoadSiteDomainFromUrlAction::run($urlString);

            if (is_array($resolved)) {
                $siteDomain = $resolved[0];
            }
        }

        // A row pins its historical ownership; a string uses only its resolved/supplied domain.
        // Null is an exact owner value, never permission to select every matching URL hash.
        $siteId = $selectedCachedModelUrl instanceof CachedModelUrl ? $selectedCachedModelUrl->site_id : $siteDomain?->site_id;
        $siteDomainId = $selectedCachedModelUrl instanceof CachedModelUrl ? $selectedCachedModelUrl->site_domain_id : $siteDomain?->id;
        $cachedModelUrls = $this->cachedModelUrls($urlString, $siteId, $siteDomainId);

        // An unresolved string can still clear legacy tracking, but cannot attribute files.
        if ($selectedCachedModelUrl === null && ! $siteDomain instanceof SiteDomain) {
            $cachedModelUrls = CachedModelUrl::query()
                ->with(['language', 'siteDomain'])
                ->where('url_hash', CachedModelUrl::hashUrl($urlString))
                ->whereNull('site_domain_id')
                ->get();
        }
        if (! $domainProvided && $selectedCachedModelUrl === null && $cachedModelUrls->isEmpty() && $siteDomain instanceof SiteDomain) {
            $cachedModelUrls = CachedModelUrl::query()
                ->with(['language', 'siteDomain'])
                ->where('url_hash', CachedModelUrl::hashUrl($urlString))
                ->where('site_id', $siteDomain->site_id)
                ->whereNull('site_domain_id')
                ->get();
        }
        $unresolvedHistoricalOwner = $cachedModelUrls->contains(static fn (CachedModelUrl $row): bool => ! $row->siteDomain instanceof SiteDomain && ($row->site_id !== null || $row->site_domain_id !== null));
        if (! $domainProvided && ($unresolvedHistoricalOwner || ! $siteDomain instanceof SiteDomain)) {
            $this->purgeEdgeCache($cachedModelUrls, $urlString);
            $cachedModelUrls->each->delete();

            return false;
        }

        $fileSiteDomain = $selectedCachedModelUrl instanceof CachedModelUrl && $selectedCachedModelUrl->site_domain_id === null
            ? null
            : $siteDomain;
        DeleteCachedUrlArtefactsAction::run($request, $fileSiteDomain, storedPaths: array_values($cachedModelUrls->map(static fn (CachedModelUrl $row): string => $row->path)->all()), includeVariants: true);

        $this->purgeEdgeCache($cachedModelUrls, $urlString);
        $cachedModelUrls->each->delete();

        $domainResolved = $siteDomain instanceof SiteDomain && filled($siteDomain->domain);
        if ($refresh && $domainResolved) {
            VisitUrlAction::dispatch($urlString);
        }

        return $domainResolved;
    }

    /**
     * @return Collection<int, CachedModelUrl>
     */
    private function cachedModelUrls(string $url, ?int $siteId, ?int $siteDomainId): Collection
    {
        return CachedModelUrl::query()
            ->with([
                // Deleted domains still identify the historical artefacts that must be removed.
                'siteDomain' => static fn (BuilderContract $query): BuilderContract => $query->withTrashed(),
                'language',
            ])
            ->where('url_hash', CachedModelUrl::hashUrl($url))
            ->where('site_id', $siteId)
            ->where('site_domain_id', $siteDomainId)
            ->get();
    }

    /**
     * @param  Collection<int, CachedModelUrl>  $cachedModelUrls
     */
    private function purgeEdgeCache(Collection $cachedModelUrls, string $url): void
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

        $keys = array_values(SurrogateKeyNormalizer::normalize($keys));

        PurgeEdgeCacheAction::dispatchAfterCommit(new EdgeCachePurgeData(
            tags: $keys,
            urls: filter_var($url, FILTER_VALIDATE_URL) !== false ? [$url] : [],
        ));
    }
}
