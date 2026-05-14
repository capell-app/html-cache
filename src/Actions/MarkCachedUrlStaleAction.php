<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Core\Actions\LoadSiteDomainFromUrlAction;
use Capell\Core\Models\SiteDomain;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Models\StaleCachedUrl;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Lorisleiva\Actions\Concerns\AsJob;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static int run(string|CachedModelUrl $url, string $reason = 'manual', ?SiteDomain $cachePathSiteDomain = null)
 */
final class MarkCachedUrlStaleAction
{
    use AsJob;
    use AsObject;

    public function handle(string|CachedModelUrl $url, string $reason = 'manual', ?SiteDomain $cachePathSiteDomain = null): int
    {
        if ($url instanceof CachedModelUrl) {
            return $this->markCachedModelUrl($url, $reason, $cachePathSiteDomain) ? 1 : 0;
        }

        $cachedModelUrls = $this->cachedModelUrls($url);

        if ($cachedModelUrls->isEmpty()) {
            return $this->markUrl($url, $reason) ? 1 : 0;
        }

        $marked = 0;
        $seenKeys = [];

        foreach ($cachedModelUrls as $cachedModelUrl) {
            $staleKey = StaleCachedUrl::staleKey(
                $cachedModelUrl->url_hash,
                $cachedModelUrl->site_id,
                $cachedModelUrl->site_domain_id,
                $cachedModelUrl->path,
            );

            if (isset($seenKeys[$staleKey])) {
                continue;
            }

            $seenKeys[$staleKey] = true;

            if ($this->markCachedModelUrl($cachedModelUrl, $reason, $cachePathSiteDomain)) {
                $marked++;
            }
        }

        return $marked;
    }

    /**
     * @return Collection<int, CachedModelUrl>
     */
    private function cachedModelUrls(string $url): Collection
    {
        $urlHash = CachedModelUrl::hashUrl($url);

        return CachedModelUrl::query()
            ->with('siteDomain')
            ->select('cached_model_urls.*')
            ->joinSub(
                CachedModelUrl::query()
                    ->selectRaw('min(id) as selected_id')
                    ->where('url_hash', $urlHash)
                    ->groupBy('url_hash', 'site_id', 'site_domain_id', 'path'),
                'unique_cached_urls',
                'cached_model_urls.id',
                '=',
                'unique_cached_urls.selected_id',
            )
            ->get();
    }

    private function markCachedModelUrl(CachedModelUrl $cachedModelUrl, string $reason, ?SiteDomain $cachePathSiteDomain): bool
    {
        $cachedModelUrl->loadMissing('siteDomain');

        $siteDomain = $cachedModelUrl->siteDomain;
        $pathResolver = resolve(HtmlCachePathResolver::class);
        $cachePath = null;
        $errorCachePath = null;
        $pathSiteDomain = $cachePathSiteDomain instanceof SiteDomain ? $cachePathSiteDomain : $siteDomain;

        if ($pathSiteDomain instanceof SiteDomain) {
            $cachePath = $pathResolver->pathForUrl($cachedModelUrl->path, $pathSiteDomain);
            $errorCachePath = $pathResolver->pathForUrl($cachedModelUrl->path, $pathSiteDomain, error: true);
        }

        $this->upsertStaleUrl(
            url: $cachedModelUrl->url,
            urlHash: $cachedModelUrl->url_hash,
            path: $cachedModelUrl->path,
            siteId: $cachedModelUrl->site_id,
            siteDomainId: $cachedModelUrl->site_domain_id,
            languageId: $cachedModelUrl->language_id,
            cachePath: $cachePath,
            errorCachePath: $errorCachePath,
            reason: $reason,
        );

        return true;
    }

    private function markUrl(string $url, string $reason): bool
    {
        if ($url === '') {
            return false;
        }

        $resolved = LoadSiteDomainFromUrlAction::run($url);
        $siteDomain = is_array($resolved) && ($resolved[0] ?? null) instanceof SiteDomain ? $resolved[0] : null;
        $path = is_array($resolved) && is_string($resolved[1] ?? null)
            ? $resolved[1]
            : resolve(HtmlCachePathResolver::class)->normalizePathFromUrl($url);
        $urlHash = CachedModelUrl::hashUrl($url);
        $cachePath = null;
        $errorCachePath = null;

        if ($siteDomain instanceof SiteDomain) {
            $pathResolver = resolve(HtmlCachePathResolver::class);
            $cachePath = $pathResolver->pathForUrl($path, $siteDomain);
            $errorCachePath = $pathResolver->pathForUrl($path, $siteDomain, error: true);
        }

        $this->upsertStaleUrl(
            url: $url,
            urlHash: $urlHash,
            path: $path,
            siteId: $siteDomain?->site_id,
            siteDomainId: $siteDomain?->getKey(),
            languageId: $siteDomain?->language_id,
            cachePath: $cachePath,
            errorCachePath: $errorCachePath,
            reason: $reason,
        );

        return true;
    }

    private function upsertStaleUrl(
        string $url,
        string $urlHash,
        string $path,
        ?int $siteId,
        ?int $siteDomainId,
        ?int $languageId,
        ?string $cachePath,
        ?string $errorCachePath,
        string $reason,
    ): void {
        $now = CarbonImmutable::now();

        StaleCachedUrl::query()->upsert(
            [[
                'stale_key' => StaleCachedUrl::staleKey($urlHash, $siteId, $siteDomainId, $path),
                'url' => $url,
                'url_hash' => $urlHash,
                'path' => $path,
                'site_id' => $siteId,
                'site_domain_id' => $siteDomainId,
                'language_id' => $languageId,
                'cache_path' => $cachePath,
                'error_cache_path' => $errorCachePath,
                'reason' => $reason,
                'status' => StaleCachedUrl::STATUS_PENDING,
                'claim_token' => null,
                'attempts' => 0,
                'processed_at' => null,
                'failed_at' => null,
                'last_error' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['stale_key'],
            [
                'url',
                'url_hash',
                'path',
                'site_id',
                'site_domain_id',
                'language_id',
                'cache_path',
                'error_cache_path',
                'reason',
                'status',
                'claim_token',
                'processed_at',
                'failed_at',
                'last_error',
                'created_at',
                'updated_at',
            ],
        );
    }
}
