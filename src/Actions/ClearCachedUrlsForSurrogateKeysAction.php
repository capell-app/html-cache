<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Core\Models\Page;
use Capell\Core\Models\SiteDomain;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Capell\HtmlCache\Support\Cache\HtmlCachePublicationGuard;
use Capell\HtmlCache\Support\Cache\HtmlCacheStore;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsJob;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static int run(array<int, string> $surrogateKeys)
 */
final class ClearCachedUrlsForSurrogateKeysAction
{
    use AsFake;
    use AsJob;
    use AsObject;

    public bool $jobDeleteWhenMissingModels = true;

    /**
     * @param  array<int, string>  $surrogateKeys
     */
    public function handle(array $surrogateKeys): int
    {
        $cleared = 0;
        $resolver = resolve(ResolveCachedUrlsForSurrogateKeysAction::class);
        if ($resolver->siteIds($surrogateKeys) !== []) {
            // A render may hold a token before either its directory or index
            // exists. Fence it even when there are no artefacts to discover.
            resolve(HtmlCachePublicationGuard::class)->invalidate(static fn (): null => null);
        }

        $pageMorphClass = (new Page)->getMorphClass();

        foreach ($resolver->pageIds($surrogateKeys) as $pageId) {
            $cleared += ClearCachedUrlsForModelAction::run($pageMorphClass, $pageId);
        }

        $cachedUrls = $resolver->siteCachedUrls($surrogateKeys);

        foreach ($cachedUrls as $cachedUrl) {
            if (ClearCachedUrlAction::run($cachedUrl, allPorts: true)) {
                $cleared++;
            }
        }

        $store = resolve(HtmlCacheStore::class);
        $paths = resolve(HtmlCachePathResolver::class);
        // Wildcard mappings have no concrete root. Their indexed request URLs
        // were cleared above; never invent an origin for unindexed port copies.
        $domains = SiteDomain::query()->withTrashed()
            ->whereIn('site_id', $resolver->siteIds($surrogateKeys))
            ->whereNotNull('scheme')
            ->whereNotNull('domain')
            ->get();
        foreach ($domains as $domain) {
            if (! $paths->hasSafeKey($domain->root_url)) {
                continue;
            }

            // Pages without model dependencies never enter the URL index.
            $cleared += $this->clearUnindexedStandardPages($domain, $store, $paths);

            foreach ($store->portDirectories($paths->directoryForSiteDomain($domain)) as $directory) {
                if (preg_match('/^~port-(\d+)\//', $directory, $matches) !== 1
                    || ! CanClearHtmlCachePortCopyAction::run(Request::create($domain->scheme . '://' . $domain->domain . ':' . $matches[1] . '/'), $domain, entireMount: true)) {
                    continue;
                }

                $files = $store->allFiles($directory);
                if ($files !== []) {
                    $store->deleteDirectory($directory);
                    $cleared += count(array_filter($files, static fn (string $file): bool => str_ends_with($file, '.html')));
                }
            }
        }

        return $cleared;
    }

    private function clearUnindexedStandardPages(SiteDomain $domain, HtmlCacheStore $store, HtmlCachePathResolver $paths): int
    {
        $origin = Request::create($domain->root_url);
        if ($paths->nonStandardPortForRequest($origin) !== null) {
            return 0;
        }

        $root = $paths->rootForRequest($origin, $domain);
        $directory = $paths->directoryForSiteDomain($domain);
        $files = $store->allFiles($directory);
        if ($directory !== $root) {
            // A mounted homepage is beside its directory: /selected -> selected.html.
            $homepage = $paths->pathForRequestUrl($domain->full_url, $domain);
            foreach ($store->files(dirname($homepage)) as $file) {
                if ($this->isHomepageArtefact($file, $homepage)) {
                    $files[] = $file;
                }
            }
        }

        $mounts = [];
        foreach (SiteDomain::query()->withTrashed()->where('domain', $domain->domain)->get() as $mount) {
            if ($paths->rootForRequest(Request::create($mount->root_url), $mount) === $root) {
                $mounts[$paths->directoryForSiteDomain($mount)] = $paths->pathForRequestUrl($mount->full_url, $mount);
            }
        }
        uksort($mounts, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        $cleared = 0;

        foreach (array_unique($files) as $file) {
            if (! $store->isSafePagePath($file, $root)) {
                continue;
            }

            $relative = substr($file, strlen($root) + 1);
            $pagePath = preg_replace('/(?:~f?[a-f0-9]{16})?(?:\.404)?\.(?:html|json|xml)(?:\.fragments\.json)?$/', '', $relative) ?? $relative;
            foreach ($mounts as $mountDirectory => $homepage) {
                if (! str_starts_with($file, $mountDirectory . '/') && ! $this->isHomepageArtefact($file, $homepage)) {
                    continue;
                }

                // Suffix-looking mount names are literal URL bytes. Never strip
                // /neighbour.404.html back into the root site's /neighbour.
                $mountPath = substr($mountDirectory, strlen($root) + 1);
                if ($mountPath !== '' && $pagePath !== $mountPath && ! str_starts_with($pagePath, $mountPath . '/')) {
                    $pagePath = $mountPath;
                }
                break;
            }
            $path = implode('/', array_map(rawurlencode(...), explode('/', $pagePath)));
            $request = Request::create($origin->getSchemeAndHttpHost() . '/' . $path);
            // A nested mount can belong to another site on the same origin.
            if (CanClearHtmlCachePortCopyAction::run($request, $domain)
                && $store->deletePage($file, $root)
                && str_ends_with($file, '.html')) {
                $cleared++;
            }
        }

        return $cleared;
    }

    private function isHomepageArtefact(string $file, string $homepage): bool
    {
        $stem = substr($homepage, 0, -strlen('.html'));

        return preg_match('/^' . preg_quote($stem, '/') . '(?:~f?[a-f0-9]{16})?(?:\.404)?\.(?:html|json|xml)(?:\.fragments\.json)?$/', $file) === 1;
    }
}
