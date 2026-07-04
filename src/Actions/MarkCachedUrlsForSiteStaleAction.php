<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Models\StaleCachedUrl;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Carbon\CarbonImmutable;
use Lorisleiva\Actions\Concerns\AsJob;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Cached URLs are keyed by `cacheable_type`/`cacheable_id` (the page-like
 * model a URL renders), never by the owning Site, so a Site attribute change
 * — most notably switching which Theme it renders with — has no cacheable
 * row of its own to invalidate through the generic model-invalidation path.
 * This marks every cached URL that belongs to the site stale directly via
 * its `site_id` column instead.
 *
 * @method static int run(int $siteId, string $reason = 'site_theme_switched')
 */
final class MarkCachedUrlsForSiteStaleAction
{
    use AsJob;
    use AsObject;

    public function handle(int $siteId, string $reason = 'site_theme_switched'): int
    {
        $marked = 0;
        $rows = [];
        $pathResolver = resolve(HtmlCachePathResolver::class);

        CachedModelUrl::query()
            ->with('siteDomain')
            ->select('cached_model_urls.*')
            ->where('site_id', $siteId)
            ->orderBy('cached_model_urls.id')
            ->lazyById(column: 'cached_model_urls.id', alias: 'id')
            ->each(function (CachedModelUrl $cachedModelUrl) use (&$marked, &$rows, $reason, $pathResolver): void {
                $cachePath = $cachedModelUrl->siteDomain !== null
                    ? $pathResolver->pathForUrl($cachedModelUrl->path, $cachedModelUrl->siteDomain)
                    : null;
                $errorCachePath = $cachedModelUrl->siteDomain !== null
                    ? $pathResolver->pathForUrl($cachedModelUrl->path, $cachedModelUrl->siteDomain, error: true)
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

        return $marked;
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
