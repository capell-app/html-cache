<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Core\Models\SiteDomain;
use Capell\HtmlCache\Models\CachedModelUrl;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsJob;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static int run(string $reason = 'all_changed', ?array $cachePathSiteDomainAttributes = null)
 */
final class MarkAllCachedUrlsStaleAction
{
    use AsJob;
    use AsObject;

    /**
     * @param  array<string, mixed>|null  $cachePathSiteDomainAttributes
     */
    public function handle(string $reason = 'all_changed', ?array $cachePathSiteDomainAttributes = null): int
    {
        $marked = 0;
        $cachePathSiteDomain = $this->cachePathSiteDomain($cachePathSiteDomainAttributes);

        CachedModelUrl::query()
            ->with('siteDomain')
            ->select('cached_model_urls.*')
            ->join(DB::raw('(select min(id) as selected_id from cached_model_urls group by url_hash, site_id, site_domain_id, path) as unique_cached_urls'), 'cached_model_urls.id', '=', 'unique_cached_urls.selected_id')
            ->orderBy('cached_model_urls.id')
            ->lazyById(column: 'cached_model_urls.id', alias: 'id')
            ->each(function (CachedModelUrl $cachedModelUrl) use (&$marked, $reason, $cachePathSiteDomain): void {
                $pathSiteDomain = $this->shouldUseCachePathSiteDomain($cachedModelUrl, $cachePathSiteDomain)
                    ? $cachePathSiteDomain
                    : null;

                $marked += MarkCachedUrlStaleAction::run($cachedModelUrl, reason: $reason, cachePathSiteDomain: $pathSiteDomain);
            });

        if ($marked === 0) {
            ClearAllHtmlCacheAction::run();
        }

        return $marked;
    }

    /**
     * @param  array<string, mixed>|null  $attributes
     */
    private function cachePathSiteDomain(?array $attributes): ?SiteDomain
    {
        if ($attributes === null || $attributes === []) {
            return null;
        }

        $siteDomain = new SiteDomain;
        $siteDomain->forceFill([
            'id' => $attributes['id'] ?? null,
            'site_id' => $attributes['site_id'] ?? null,
            'language_id' => $attributes['language_id'] ?? null,
            'scheme' => $attributes['scheme'] ?? null,
            'domain' => $attributes['domain'] ?? null,
            'path' => $attributes['path'] ?? null,
            'status' => $attributes['status'] ?? true,
        ]);
        $siteDomain->exists = true;

        return $siteDomain;
    }

    private function shouldUseCachePathSiteDomain(CachedModelUrl $cachedModelUrl, ?SiteDomain $cachePathSiteDomain): bool
    {
        if (! $cachePathSiteDomain instanceof SiteDomain) {
            return false;
        }

        return $cachedModelUrl->site_domain_id === $cachePathSiteDomain->getKey();
    }
}
