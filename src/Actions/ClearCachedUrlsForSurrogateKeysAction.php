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
        foreach (SiteDomain::query()->withTrashed()->whereIn('site_id', $resolver->siteIds($surrogateKeys))->get() as $domain) {
            // Pages without model dependencies never enter the URL index. Only
            // isolated roots need this fallback; legacy roots keep their policy.
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
}
