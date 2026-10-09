<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Core\Models\SiteDomain;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Models\StaleCachedUrl;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Capell\HtmlCache\Support\Cache\HtmlCachePublicationGuard;
use Capell\HtmlCache\Support\Cache\HtmlCacheStore;
use Capell\HtmlCache\Support\Cache\StatelessPaginationRequest;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static bool run(Request $request, ?SiteDomain $siteDomain = null, list<string> $storedFiles = [], list<string> $storedPaths = [], bool $includeVariants = false, ?HtmlCacheStore $store = null, list<string> $preserve = [], bool $rotateWhenUnchanged = true, bool $fenceUrlWhenUnchanged = true, ?Closure $guard = null)
 */
final class DeleteCachedUrlArtefactsAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  list<string>  $storedFiles
     * @param  list<string>  $storedPaths
     * @param  list<string>  $preserve
     * @param  bool  $rotateWhenUnchanged  false only for retirements that observe origin policy rather than act on a data change
     * @param  bool  $fenceUrlWhenUnchanged  false only for cleanup after this caller's own guarded publish of the URL
     * @param  Closure(Closure(): bool): bool|null  $guard  see HtmlCacheStore::deletePagesInDomain()
     */
    public function handle(Request $request, ?SiteDomain $siteDomain = null, array $storedFiles = [], array $storedPaths = [], bool $includeVariants = false, ?HtmlCacheStore $store = null, array $preserve = [], bool $rotateWhenUnchanged = true, bool $fenceUrlWhenUnchanged = true, ?Closure $guard = null): bool
    {
        $paths = resolve(HtmlCachePathResolver::class);
        if (! $paths->hasSafeKey($request)) {
            return false;
        }
        $domainDirectory = $paths->rootForRequest($request, $siteDomain);
        $files = [
            $paths->pathForRequestUrl($request, $siteDomain),
            $paths->pathForRequestUrl($request, $siteDomain, error: true),
            $domainDirectory . '/' . $paths->relativePathForRequest($request, '.json'),
            $domainDirectory . '/' . $paths->relativePathForRequest($request, '.xml'),
            ...$storedFiles,
        ];
        $urlRequest = clone $request;
        $urlRequest->headers->remove(StatelessPaginationRequest::FRAGMENT_HEADER);
        $variantBases = [$paths->pathForRequestUrl($urlRequest, $siteDomain)];
        $pageRequest = clone $urlRequest;
        $pageRequest->query->replace([]);
        // Conservative eviction covers every unattributable legacy hash on this
        // page path, including full query responses as well as fragments. The
        // store excludes canonical no-query keys and recorded neighbour ownership.
        $unattributableLegacyBases = [$paths->pathForRequestUrl($pageRequest, $siteDomain)];
        if (isset($storedFiles[0])) {
            $variantBases[] = $storedFiles[0];
            $suffix = StatelessPaginationRequest::cacheKeySuffix($urlRequest);
            $unattributableLegacyBases[] = $suffix === '' ? $storedFiles[0] : str_replace($suffix . '.html', '.html', $storedFiles[0]);
        }
        foreach ($storedPaths as $path) {
            try {
                $historical = $paths->historicalPathsForStoredPath($path, $request, $siteDomain);
                $files = [...$files, ...$historical];
                $variantBases[] = $paths->historicalPathsForStoredPath($path, $urlRequest, $siteDomain)[0];
                $unattributableLegacyBases[] = $paths->historicalPathsForStoredPath($path, $pageRequest, $siteDomain)[0];
            } catch (InvalidArgumentException) {
                // Corrupt tracking must never redirect deletion outside its owner.
            }
        }

        if ($request->headers->has(StatelessPaginationRequest::FRAGMENT_HEADER)) {
            // Independently keyed fragments are attributable even though publication
            // retains the baseline combined hash for upgrade compatibility.
            $fragmentSuffix = '~f' . mb_substr(hash('xxh128', (string) $request->headers->get(StatelessPaginationRequest::FRAGMENT_HEADER)), 0, 16);
            foreach ($variantBases as $base) {
                $stem = substr($base, 0, -strlen('.html')) . $fragmentSuffix;
                $files = [...$files, $stem . '.html', $stem . '.404.html', $stem . '.json', $stem . '.xml'];
            }
        }
        if ($includeVariants) {
            $files = [...$files, ...$this->recordedFiles($siteDomain, $paths, $urlRequest, $storedPaths, $unattributableLegacyBases, $urlRequest->fullUrl())];
        }

        // The request still identifies its full-response keys after fragment
        // retirement removes tracking; later retirement must preserve those keys.
        return ($store ?? resolve(HtmlCacheStore::class))->deletePagesInDomain(
            $files,
            $includeVariants ? $variantBases : [],
            $domainDirectory,
            $unattributableLegacyBases,
            [...$variantBases, ...$this->recordedFiles($siteDomain, $paths, $urlRequest, $storedPaths, $unattributableLegacyBases)],
            $preserve,
            $rotateWhenUnchanged,
            $fenceUrlWhenUnchanged ? HtmlCachePublicationGuard::urlKey($request) : null,
            $guard,
        );
    }

    /**
     * @param  list<string>  $storedPaths
     * @param  list<string>  $legacyBases
     * @return list<string>
     */
    private function recordedFiles(?SiteDomain $siteDomain, HtmlCachePathResolver $paths, Request $target, array $storedPaths, array $legacyBases, ?string $url = null): array
    {
        $files = [];
        foreach ([CachedModelUrl::class, StaleCachedUrl::class] as $model) {
            if (! Schema::hasTable((new $model)->getTable())) {
                continue;
            }
            $query = $model::query()->where('site_id', $siteDomain?->site_id)->where('site_domain_id', $siteDomain?->id);
            if ($url !== null) {
                $query->where('url_hash', CachedModelUrl::hashUrl($url));
            } else {
                // Only these path families can collide with the legacy sweep.
                // Never hydrate the complete site's dependency and stale indexes per URL.
                $targetPaths = array_values(array_unique([
                    $target->getPathInfo(), rawurldecode($target->getPathInfo()),
                    '/' . implode('/', array_map(rawurlencode(...), $target->segments())),
                    ...$storedPaths,
                ]));
                if (in_array($target->getPathInfo(), ['/', '/index'], true)) {
                    $targetPaths = [...$targetPaths, '/', '/index'];
                }
                $query->where(function (Builder $query) use ($targetPaths, $legacyBases, $model): void {
                    $query->whereIn('path', $targetPaths);
                    if ($model === StaleCachedUrl::class) {
                        foreach ($legacyBases as $base) {
                            // Explicit ESCAPE works with SQLite and MariaDB alike.
                            $prefix = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], substr($base, 0, -strlen('.html')));
                            $query->orWhereRaw("cache_path LIKE ? ESCAPE '!'", [$prefix . '%'])
                                ->orWhereRaw("error_cache_path LIKE ? ESCAPE '!'", [$prefix . '%']);
                        }
                    }
                });
            }
            $rows = $query->get();
            foreach ($rows as $row) {
                if ($row instanceof StaleCachedUrl) {
                    foreach ([$row->cache_path, $row->error_cache_path] as $file) {
                        if (is_string($file) && $file !== '') {
                            $files[] = $file;
                        }
                    }
                }
                try {
                    $request = Request::create($row->url);
                    $files[] = $paths->pathForRequestUrl($request, $siteDomain);
                    $files = [...$files, ...$paths->historicalPathsForStoredPath($row->path, $request, $siteDomain)];
                } catch (InvalidArgumentException) {
                    // Unresolvable rows cannot attribute opaque files to a URL.
                }
            }
        }

        return array_values(array_unique($files));
    }
}
