<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Core\Models\SiteDomain;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Models\StaleCachedUrl;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Carbon\CarbonImmutable;
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
        $rows = [];
        $cachePathSiteDomain = $this->cachePathSiteDomain($cachePathSiteDomainAttributes);
        $pathResolver = resolve(HtmlCachePathResolver::class);

        CachedModelUrl::query()
            ->with('siteDomain')
            ->select('cached_model_urls.*')
            ->join(DB::raw('(select min(id) as selected_id from cached_model_urls group by url_hash, site_id, site_domain_id, path) as unique_cached_urls'), 'cached_model_urls.id', '=', 'unique_cached_urls.selected_id')
            ->orderBy('cached_model_urls.id')
            ->lazyById(column: 'cached_model_urls.id', alias: 'id')
            ->each(function (CachedModelUrl $cachedModelUrl) use (&$marked, &$rows, $reason, $cachePathSiteDomain, $pathResolver): void {
                $pathSiteDomain = $this->shouldUseCachePathSiteDomain($cachedModelUrl, $cachePathSiteDomain)
                    ? $cachePathSiteDomain
                    : $cachedModelUrl->siteDomain;

                $cachePath = $pathSiteDomain instanceof SiteDomain
                    ? $pathResolver->pathForUrl($cachedModelUrl->path, $pathSiteDomain)
                    : null;
                $errorCachePath = $pathSiteDomain instanceof SiteDomain
                    ? $pathResolver->pathForUrl($cachedModelUrl->path, $pathSiteDomain, error: true)
                    : null;

                $rows[] = $this->staleUrlRow($cachedModelUrl, $cachePath, $errorCachePath, $reason);

                $marked++;

                if (count($rows) >= 500) {
                    $this->upsertStaleUrls($rows);
                    $rows = [];
                }
            });

        if ($rows !== []) {
            $this->upsertStaleUrls($rows);
        }

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

    /**
     * @return array<string, mixed>
     */
    private function staleUrlRow(
        CachedModelUrl $cachedModelUrl,
        ?string $cachePath,
        ?string $errorCachePath,
        string $reason,
    ): array {
        $now = CarbonImmutable::now();

        return [
            'stale_key' => StaleCachedUrl::staleKey(
                $cachedModelUrl->url_hash,
                $cachedModelUrl->site_id,
                $cachedModelUrl->site_domain_id,
                $cachedModelUrl->path,
            ),
            'url' => $cachedModelUrl->url,
            'url_hash' => $cachedModelUrl->url_hash,
            'path' => $cachedModelUrl->path,
            'site_id' => $cachedModelUrl->site_id,
            'site_domain_id' => $cachedModelUrl->site_domain_id,
            'language_id' => $cachedModelUrl->language_id,
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
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function upsertStaleUrls(array $rows): void
    {
        StaleCachedUrl::query()->upsert(
            $rows,
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
                'attempts',
                'processed_at',
                'failed_at',
                'last_error',
                'created_at',
                'updated_at',
            ],
        );
    }
}
