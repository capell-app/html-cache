<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions\Dashboard;

use Capell\Admin\Support\SiteScope;
use Capell\Core\Models\PageUrl;
use Capell\HtmlCache\Models\CachedModelUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * @method static Collection<int, array{id: string, state: string, url: string, site: string, hits: int, last_hit: string, cached_at: string, last_seen: string}> run(string $mode, int $limit = 5)
 */
final class BuildHtmlCacheUrlRowsAction
{
    use AsAction;

    /**
     * @return Collection<int, array{id: string, state: string, url: string, site: string, hits: int, last_hit: string, cached_at: string, last_seen: string}>
     */
    public function handle(string $mode, int $limit = 5): Collection
    {
        if ($mode === 'cached') {
            return $this->cachedRows($limit);
        }

        if ($mode === 'coverage') {
            return $this->uncachedRows(3)
                ->merge($this->cachedRows(3))
                ->values();
        }

        return $this->uncachedRows($limit);
    }

    /**
     * @return Collection<int, array{id: string, state: string, url: string, site: string, hits: int, last_hit: string, cached_at: string, last_seen: string}>
     */
    private function uncachedRows(int $limit): Collection
    {
        return $this->pageUrlQuery()
            ->whereNotExists($this->cachedUrlExists(...))
            ->with('site')
            ->orderByDesc('hit_count')
            ->orderByDesc('last_hit_at')
            ->limit($limit)
            ->get()
            ->map(fn (PageUrl $pageUrl): array => [
                'id' => 'uncached-page-url-' . $pageUrl->id,
                'state' => __('capell-html-cache::dashboard.uncached'),
                'url' => $pageUrl->url,
                'site' => (string) ($pageUrl->site?->name ?? '-'),
                'hits' => (int) $pageUrl->hit_count,
                'last_hit' => $pageUrl->last_hit_at?->diffForHumans() ?? '-',
                'cached_at' => '-',
                'last_seen' => $pageUrl->last_hit_at?->diffForHumans() ?? '-',
            ])
            ->values();
    }

    /**
     * @return Collection<int, array{id: string, state: string, url: string, site: string, hits: int, last_hit: string, cached_at: string, last_seen: string}>
     */
    private function cachedRows(int $limit): Collection
    {
        return SiteScope::applyForCurrentActor(CachedModelUrl::query(), denyWhenMissingActor: true)
            ->with('site')
            ->orderByDesc('last_seen_at')
            ->limit($limit)
            ->get()
            ->map(fn (CachedModelUrl $cachedUrl): array => [
                'id' => 'cached-url-' . $cachedUrl->id,
                'state' => __('capell-html-cache::dashboard.cached'),
                'url' => $cachedUrl->url,
                'site' => (string) ($cachedUrl->site?->name ?? '-'),
                'hits' => 0,
                'last_hit' => '-',
                'cached_at' => $cachedUrl->cached_at?->diffForHumans() ?? '-',
                'last_seen' => $cachedUrl->last_seen_at?->diffForHumans() ?? '-',
            ])
            ->values();
    }

    /**
     * @return Builder<PageUrl>
     */
    private function pageUrlQuery(): Builder
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
