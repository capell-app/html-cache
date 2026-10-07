<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Core\Models\SiteDomain;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static bool run(Request $request, ?SiteDomain $siteDomain, bool $entireMount = false)
 */
final class CanClearHtmlCachePortCopyAction
{
    use AsFake;
    use AsObject;

    public function handle(Request $request, ?SiteDomain $siteDomain, bool $entireMount = false): bool
    {
        if (! $siteDomain instanceof SiteDomain) {
            return false;
        }

        $paths = resolve(HtmlCachePathResolver::class);
        $path = '/' . $paths->directoryForPath($entireMount ? ($siteDomain->path ?? '') : $request->getPathInfo());
        $pageRequest = clone $request;
        $pageRequest->query->replace([]);

        $pageKey = $paths->relativePathForRequest($pageRequest);
        $domains = SiteDomain::query()->withTrashed()->where('domain', $siteDomain->domain)->get();
        $port = $paths->nonStandardPortForRequest($request);
        $onPort = $domains->filter(fn (SiteDomain $domain): bool => $paths->nonStandardPortForRequest(Request::create($domain->root_url)) === $port);
        $configuredOwners = $this->configuredOwners($onPort, $path, $entireMount);

        // A configured owner on this port wins over historical copies from another site.
        if (array_diff($configuredOwners, [$siteDomain->site_id]) !== []) {
            return false;
        }

        $indexedOwners = [];
        $origin = $siteDomain->scheme . '://' . $siteDomain->domain;
        $prefix = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $origin);
        foreach (CachedModelUrl::query()->whereRaw("url LIKE ? ESCAPE '!'", [$prefix . '%'])->get(['url', 'site_id']) as $row) {
            $indexed = Request::create($row->url);
            if ($indexed->getHost() !== $siteDomain->domain || $indexed->getScheme() !== $siteDomain->scheme
                || $paths->nonStandardPortForRequest($indexed) !== $port || ! $paths->hasSafeKey($indexed)) {
                continue;
            }

            $indexedPath = '/' . $paths->directoryForPath($indexed->getPathInfo());
            $indexed->query->replace([]);
            // Homepage aliases share a file; compare stored keys, not URL spellings.
            if ($entireMount ? $this->contains($path, $indexedPath) : $paths->relativePathForRequest($indexed) === $pageKey) {
                $indexedOwners[] = $row->site_id;
            }
        }

        if (array_diff($indexedOwners, [$siteDomain->site_id]) !== []) {
            return false;
        }

        if ($configuredOwners !== [] || $indexedOwners !== []) {
            return true;
        }

        // Without a port configuration or index, only an unambiguous host/mount owner
        // can attribute an orphan left by a domain change. Never infer it from the host alone.
        return $this->configuredOwners($domains, $path, $entireMount) === [$siteDomain->site_id];
    }

    /**
     * @param  Collection<int, SiteDomain>  $domains
     * @return list<int>
     */
    private function configuredOwners(Collection $domains, string $path, bool $entireMount): array
    {
        $owners = [];
        $longestMount = -1;
        $paths = resolve(HtmlCachePathResolver::class);
        foreach ($domains as $domain) {
            $mount = '/' . $paths->directoryForPath($domain->path ?? '');
            if ($this->contains($mount, $path)) {
                if (strlen($mount) > $longestMount) {
                    $owners = [];
                    $longestMount = strlen($mount);
                }

                if (strlen($mount) === $longestMount) {
                    $owners[] = $domain->site_id;
                }
            }
        }

        if ($entireMount) {
            foreach ($domains as $domain) {
                if ($this->contains($path, '/' . $paths->directoryForPath($domain->path ?? ''))) {
                    $owners[] = $domain->site_id;
                }
            }
        }

        return array_values(array_unique($owners));
    }

    private function contains(string $mount, string $path): bool
    {
        return $mount === '/' || $path === $mount || str_starts_with($path, $mount . '/');
    }
}
