<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Admin\Support\SiteScope;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\HtmlCache\Data\UncachedPageData;
use Capell\HtmlCache\Data\UncachedPagesData;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Models\StaleCachedUrl;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Throwable;

/** @method static UncachedPagesData run() */
final class BuildUncachedPagesAction
{
    use AsFake;
    use AsObject;

    public function handle(): UncachedPagesData
    {
        $pages = [];
        $query = SiteScope::applyForCurrentActor(PageUrl::query(), denyWhenMissingActor: true)
            ->enabled()
            ->whereHas('site', fn (Builder $site): Builder => $site->enabled())
            ->whereHas('pageable', fn (Builder $page): Builder => $page
                ->whereIn('blueprint_id', Blueprint::query()->enabled()->accessible()->select('id')))
            ->with(['site.siteDomains', 'pageable'])
            ->orderBy('id');

        foreach ($query->lazy(100) as $pageUrl) {
            $site = $pageUrl->site;
            if (! $site instanceof Site) {
                throw new RuntimeException('The public URL no longer has an accessible site.');
            }

            $domains = $site->siteDomains->where('status', true)->where('language_id', $pageUrl->language_id);

            if ($domains->isEmpty()) {
                $pages['missing-domain:' . $pageUrl->id] = new UncachedPageData(
                    pageUrlId: $pageUrl->id,
                    url: $pageUrl->url,
                    site: $site->name,
                    reasons: ['missing_site_domain'],
                    refreshStatus: null,
                    lastError: null,
                    lastCachedAt: null,
                    cachedHits: 0,
                );

                continue;
            }

            foreach ($domains as $domain) {
                $row = $this->inspect($pageUrl, $domain, $site);
                if ($row instanceof UncachedPageData) {
                    $pages[$row->url] = $row;
                }
            }
        }

        return new UncachedPagesData(array_values($pages));
    }

    private function inspect(PageUrl $pageUrl, SiteDomain $domain, Site $site): ?UncachedPageData
    {
        $pageUrl->setRelation('siteDomain', $domain);
        $url = $pageUrl->fullUrl();
        $request = Request::create($url);
        $report = BuildAnonymousHtmlCacheEligibilityReportAction::run($request, pageUrl: $pageUrl, inspectCacheState: false);

        $reasons = $report->reasonCodes();
        $cacheIssues = [];
        $path = null;
        try {
            $path = resolve(HtmlCachePathResolver::class)->pathForRequestUrl($request, $domain);
            $entryReasons = $this->entryReasons(Storage::disk('page_cache'), $path);
            if ($entryReasons === []) {
                return null;
            }

            $cacheIssues = $entryReasons;
        } catch (InvalidArgumentException) {
            $cacheIssues[] = 'unsafe_request_path';
        } catch (Throwable) {
            $cacheIssues[] = 'cache_inspection_failed';
        }

        $stale = StaleCachedUrl::query()
            ->where('site_id', $pageUrl->site_id)
            ->where('language_id', $pageUrl->language_id)
            ->where('url_hash', CachedModelUrl::hashUrl($request->getUri()))
            ->latest('updated_at')->latest('id')->first();

        if ($stale === null) {
            $cacheIssues[] = 'no_recorded_attempt';
        } elseif ($path !== null && $stale->cache_path !== null && $stale->cache_path !== $path) {
            $cacheIssues[] = 'cache_path_mismatch';
        }

        // Hit batches update every dependency row; summing would count each hit repeatedly.
        $cached = CachedModelUrl::query()
            ->where('site_id', $pageUrl->site_id)
            ->where('language_id', $pageUrl->language_id)
            ->where('url_hash', CachedModelUrl::hashUrl($request->getUri()))
            ->selectRaw('MAX(hit_count) as hit_count, MAX(cached_at) as cached_at')
            ->first();
        $metrics = $report->requestMetrics;

        return new UncachedPageData(
            pageUrlId: $pageUrl->id,
            url: $request->getUri(),
            site: $site->name,
            reasons: $reasons,
            refreshStatus: $stale?->status,
            lastError: $stale?->last_error,
            lastCachedAt: $cached?->cached_at?->toIso8601String(),
            cachedHits: (int) ($cached->hit_count ?? 0),
            lastRenderMilliseconds: $metrics?->lastRenderMilliseconds,
            hitRate: $metrics?->hitRate,
            cacheIssues: $cacheIssues,
            metricsObservedSince: $metrics?->observedSince,
        );
    }

    /** @return list<string> */
    private function entryReasons(Filesystem $disk, string $path): array
    {
        if (! $disk->exists($path)) {
            return ['cache_entry_missing'];
        }

        $reasons = [];
        $ttl = config('capell-html-cache.filesystem_ttl_seconds', config('capell-html-cache.cache_ttl', 3600));
        $ttl = is_numeric($ttl) ? max(0, (int) $ttl) : 3600;
        if ($ttl > 0 && $disk->lastModified($path) <= now()->subSeconds($ttl)->timestamp) {
            $reasons[] = 'cache_entry_expired';
        }

        if ($disk->size($path) === 0) {
            $reasons[] = 'cache_entry_empty';
        }

        return $reasons;
    }
}
