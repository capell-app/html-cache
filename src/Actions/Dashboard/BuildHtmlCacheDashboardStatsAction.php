<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions\Dashboard;

use Capell\Admin\Support\SiteScope;
use Capell\Core\Models\PageUrl;
use Capell\HtmlCache\Data\Dashboard\HtmlCacheDashboardStatsData;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Models\StaleCachedUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static HtmlCacheDashboardStatsData run()
 */
final class BuildHtmlCacheDashboardStatsAction
{
    use AsFake;
    use AsObject;

    public function handle(): HtmlCacheDashboardStatsData
    {
        $pageUrlQuery = $this->cacheablePageUrlQuery();
        $pageUrlStats = (clone $pageUrlQuery)
            ->selectRaw('COUNT(*) as page_url_count')
            ->selectRaw('COALESCE(SUM(hit_count), 0) as total_hit_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN EXISTS (
                SELECT 1
                FROM cached_model_urls
                WHERE cached_model_urls.site_id = page_urls.site_id
                AND cached_model_urls.language_id = page_urls.language_id
                AND cached_model_urls.path = page_urls.url
            ) THEN 1 ELSE 0 END), 0) as cached_page_url_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN EXISTS (
                SELECT 1
                FROM cached_model_urls
                WHERE cached_model_urls.site_id = page_urls.site_id
                AND cached_model_urls.language_id = page_urls.language_id
                AND cached_model_urls.path = page_urls.url
            ) THEN hit_count ELSE 0 END), 0) as cached_hit_count')
            ->first();

        $pageUrls = (int) ($pageUrlStats?->getAttribute('page_url_count') ?? 0);
        $cachedPageUrls = (int) ($pageUrlStats?->getAttribute('cached_page_url_count') ?? 0);
        $cachedHitCount = (int) ($pageUrlStats?->getAttribute('cached_hit_count') ?? 0);
        $totalHitCount = (int) ($pageUrlStats?->getAttribute('total_hit_count') ?? 0);
        $trackedCachedUrlsQuery = SiteScope::applyForCurrentActor(CachedModelUrl::query(), denyWhenMissingActor: true)
            ->select('url_hash', 'site_id', 'language_id')
            ->groupBy('url_hash', 'site_id', 'language_id')
            ->toBase();
        $trackedCachedUrls = DB::query()
            ->fromSub($trackedCachedUrlsQuery, 'tracked_cached_urls')
            ->count();
        $staleQuery = SiteScope::applyForCurrentActor(StaleCachedUrl::query(), denyWhenMissingActor: true);

        return new HtmlCacheDashboardStatsData(
            pageUrls: $pageUrls,
            cachedPageUrls: $cachedPageUrls,
            uncachedPageUrls: max(0, $pageUrls - $cachedPageUrls),
            coverageRate: $pageUrls === 0 ? 0.0 : round(($cachedPageUrls / $pageUrls) * 100, 1),
            trackedCachedUrls: $trackedCachedUrls,
            stalePending: (clone $staleQuery)->whereIn('status', [
                StaleCachedUrl::STATUS_PENDING,
                StaleCachedUrl::STATUS_PROCESSING,
            ])->count(),
            staleFailed: (clone $staleQuery)->whereIn('status', [
                StaleCachedUrl::STATUS_FAILED,
                StaleCachedUrl::STATUS_EXHAUSTED,
            ])->count(),
            cachedTrafficCoverageRate: $totalHitCount === 0 ? 0.0 : round(($cachedHitCount / $totalHitCount) * 100, 1),
        );
    }

    /**
     * @return Builder<PageUrl>
     */
    private function cacheablePageUrlQuery(): Builder
    {
        /** @var Builder<PageUrl> $query */
        $query = PageUrl::query()
            ->where('status', true)
            ->whereNull('target_url');

        return SiteScope::applyForCurrentActor($query, denyWhenMissingActor: true);
    }
}
