<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Core\Actions\LoadSiteDomainFromUrlAction;
use Capell\Core\Actions\VisitUrlAction;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Page;
use Capell\Core\Models\SiteDomain;
use Capell\Frontend\Support\Cache\SurrogateKeyNormalizer;
use Capell\HtmlCache\Data\EdgeCachePurgeData;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Capell\HtmlCache\Support\Cache\HtmlCacheStore;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsJob;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static bool run(string|CachedModelUrl $url, ?SiteDomain $siteDomain = null, bool $refresh = false, bool $allPorts = false, bool $prefenced = false)
 */
final class ClearCachedUrlAction
{
    use AsFake;
    use AsJob;
    use AsObject;

    /**
     * @param  bool  $prefenced  true only from a targeted withdrawal that already fenced this URL; see HtmlCachePublicationGuard::fence()
     */
    public function handle(string|CachedModelUrl $url, ?SiteDomain $siteDomain = null, bool $refresh = false, bool $allPorts = false, bool $prefenced = false): bool
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

        if (! resolve(HtmlCachePathResolver::class)->hasSafeKey($urlString)) {
            return false;
        }

        $request = Request::create($urlString);
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
        if (! $selectedCachedModelUrl instanceof CachedModelUrl && ! $siteDomain instanceof SiteDomain) {
            $cachedModelUrls = CachedModelUrl::query()
                ->with(['language', 'siteDomain'])
                ->where('url_hash', CachedModelUrl::hashUrl($urlString))
                ->whereNull('site_domain_id')
                ->get();
        }

        if (! $domainProvided && ! $selectedCachedModelUrl instanceof CachedModelUrl && $cachedModelUrls->isEmpty() && $siteDomain instanceof SiteDomain) {
            $cachedModelUrls = CachedModelUrl::query()
                ->with(['language', 'siteDomain'])
                ->where('url_hash', CachedModelUrl::hashUrl($urlString))
                ->where('site_id', $siteDomain->site_id)
                ->whereNull('site_domain_id')
                ->get();
        }

        $unresolvedHistoricalOwner = $cachedModelUrls->contains(static fn (CachedModelUrl $row): bool => ! $row->siteDomain instanceof SiteDomain && ($row->site_id !== null || $row->site_domain_id !== null));
        if (! $domainProvided && ($unresolvedHistoricalOwner || ! $siteDomain instanceof SiteDomain)) {
            $this->purgeEdgeCache($cachedModelUrls, $urlString, $prefenced);
            $cachedModelUrls->each->delete();

            return false;
        }

        $fileSiteDomain = $selectedCachedModelUrl instanceof CachedModelUrl && $selectedCachedModelUrl->site_domain_id === null
            ? null
            : $siteDomain;
        DeleteCachedUrlArtefactsAction::run($request, $fileSiteDomain, storedPaths: array_values($cachedModelUrls->map(static fn (CachedModelUrl $row): string => $row->path)->all()), includeVariants: true, prefenced: $prefenced);

        if ($allPorts) {
            $paths = resolve(HtmlCachePathResolver::class);
            $root = $paths->rootForRequest($request, $fileSiteDomain);
            $copies = [];
            if ($paths->nonStandardPortForRequest($request) !== null) {
                $copies[] = Request::create($request->getScheme() . '://' . $request->getHost() . $request->getRequestUri());
            }

            // Publication precedes asynchronous dependency recording. Discover
            // copies on disk, then attribute them through domains and the index.
            foreach (resolve(HtmlCacheStore::class)->portDirectories($root) as $directory) {
                if (preg_match('/^~port-(\d+)\//', $directory, $matches) === 1 && (int) $matches[1] !== $request->getPort()) {
                    $copies[] = Request::create($request->getScheme() . '://' . $request->getHost() . ':' . $matches[1] . $request->getRequestUri());
                }
            }

            foreach ($copies as $copy) {
                if (! CanClearHtmlCachePortCopyAction::run($copy, $fileSiteDomain)) {
                    continue;
                }

                DeleteCachedUrlArtefactsAction::run($copy, $fileSiteDomain, storedPaths: array_values($cachedModelUrls->map(static fn (CachedModelUrl $row): string => $row->path)->all()), includeVariants: true, prefenced: $prefenced);
            }
        }

        $this->purgeEdgeCache($cachedModelUrls, $urlString, $prefenced);
        $cachedModelUrls->each->delete();

        $domainResolved = $siteDomain instanceof SiteDomain && filled($siteDomain->domain);
        if ($refresh && $domainResolved) {
            VisitUrlAction::dispatch($urlString, $this->pageIdFor($cachedModelUrls));
        }

        return $domainResolved;
    }

    /**
     * The page a refresh visit belongs to, so a failed visit is recorded against it.
     *
     * @param  Collection<int, CachedModelUrl>  $cachedModelUrls
     */
    private function pageIdFor(Collection $cachedModelUrls): ?int
    {
        $pageIds = $cachedModelUrls
            ->filter(static fn (CachedModelUrl $row): bool => (Relation::getMorphedModel($row->cacheable_type) ?? $row->cacheable_type) === Page::class)
            ->map(static fn (CachedModelUrl $row): int => $row->cacheable_id)
            ->unique();

        return $pageIds->count() === 1 ? $pageIds->first() : null;
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
    private function purgeEdgeCache(Collection $cachedModelUrls, string $url, bool $withdrawal): void
    {
        // Every public response carries site- and lang- tags, so purging either
        // flushes the whole site and spends the provider's scarce tag-purge
        // allowance. The URL tag covers this address's query variants; the
        // page tag covers the page's other addresses.
        $validUrl = filter_var($url, FILTER_VALIDATE_URL) !== false;

        if ($withdrawal) {
            // A withdrawal purges this URL and its query variants and nothing else.
            PurgeEdgeCacheAction::dispatchAfterCommit(new EdgeCachePurgeData(
                tags: $validUrl ? [ResolveEdgeCacheTagsAction::urlTag($url)] : [],
                urls: $validUrl ? [$url] : [],
            ), urlScopedTags: $validUrl);

            return;
        }

        $keys = [];

        foreach ($cachedModelUrls as $cachedModelUrl) {
            // Recorded rows hold the morph alias (e.g. "page"), not the class name.
            $cacheableClass = Relation::getMorphedModel($cachedModelUrl->cacheable_type) ?? $cachedModelUrl->cacheable_type;

            if (is_a($cacheableClass, Pageable::class, true)) {
                $keys[] = 'page-' . $cachedModelUrl->cacheable_id;
            }
        }

        if ($validUrl) {
            $keys[] = ResolveEdgeCacheTagsAction::urlTag($url);
        }

        $keys = array_values(SurrogateKeyNormalizer::normalize($keys));

        PurgeEdgeCacheAction::dispatchAfterCommit(new EdgeCachePurgeData(
            tags: $keys,
            urls: $validUrl ? [$url] : [],
        ), urlScopedTags: $validUrl);
    }
}
