<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions\Dashboard;

use Capell\Admin\Support\SiteScope;
use Capell\Core\Models\PageUrl;
use Capell\HtmlCache\Data\Dashboard\HtmlCacheDashboardStatsData;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Models\StaleCachedUrl;
use Illuminate\Database\Eloquent\Builder;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * @method static HtmlCacheDashboardStatsData run()
 */
final class BuildHtmlCacheDashboardStatsAction
{
    use AsAction;

    public function handle(): HtmlCacheDashboardStatsData
    {
        $pageUrlQuery = $this->cacheablePageUrlQuery();
        $pageUrls = (clone $pageUrlQuery)->count();
        $cachedPageUrls = (clone $pageUrlQuery)->whereExists($this->cachedUrlExists(...))->count();
        $cachedHitCount = (int) (clone $pageUrlQuery)->whereExists($this->cachedUrlExists(...))->sum('hit_count');
        $totalHitCount = (int) (clone $pageUrlQuery)->sum('hit_count');
        $trackedCachedUrls = SiteScope::applyForCurrentActor(CachedModelUrl::query(), denyWhenMissingActor: true)
            ->distinct('url_hash')
            ->count('url_hash');
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

    private function cachedUrlExists(\Illuminate\Database\Query\Builder $query): void
    {
        $query->selectRaw('1')
            ->from('cached_model_urls')
            ->whereColumn('cached_model_urls.site_id', 'page_urls.site_id')
            ->whereColumn('cached_model_urls.language_id', 'page_urls.language_id')
            ->whereColumn('cached_model_urls.path', 'page_urls.url');
    }
}
