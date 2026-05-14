<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions\Dashboard;

use Capell\Admin\Support\SiteScope;
use Capell\Core\Models\PageUrl;
use Capell\HtmlCache\Models\CachedModelUrl;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * @method static Collection<int, array{id: string, state: string, url: string, site: string, hits: string, last_hit: string, cached_at: string, last_seen: string}> run(string $mode, int $limit = 5)
 */
final class BuildHtmlCacheUrlRowsAction
{
    use AsAction;

    /**
     * @return Collection<int, array{id: string, state: string, url: string, site: string, hits: string, last_hit: string, cached_at: string, last_seen: string}>
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
     * @return Collection<int, array{id: string, state: string, url: string, site: string, hits: string, last_hit: string, cached_at: string, last_seen: string}>
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
                'site' => (string) ($pageUrl->site?->name ?? __('capell-html-cache::dashboard.not_available')),
                'hits' => number_format((int) $pageUrl->hit_count),
                'last_hit' => $pageUrl->last_hit_at?->diffForHumans() ?? __('capell-html-cache::dashboard.never'),
                'cached_at' => __('capell-html-cache::dashboard.not_cached'),
                'last_seen' => $pageUrl->last_hit_at?->diffForHumans() ?? __('capell-html-cache::dashboard.never'),
            ])
            ->values();
    }

    /**
     * @return Collection<int, array{id: string, state: string, url: string, site: string, hits: string, last_hit: string, cached_at: string, last_seen: string}>
     */
    private function cachedRows(int $limit): Collection
    {
        return SiteScope::applyForCurrentActor(CachedModelUrl::query(), denyWhenMissingActor: true)
            ->with('site')
            ->select('url_hash', 'url', 'site_id', 'language_id')
            ->selectRaw('MAX(id) as id')
            ->selectRaw('MAX(cached_at) as cached_at')
            ->selectRaw('MAX(last_seen_at) as last_seen_at')
            ->groupBy('url_hash', 'url', 'site_id', 'language_id')
            ->orderByDesc('last_seen_at')
            ->limit($limit)
            ->get()
            ->map(fn (CachedModelUrl $cachedUrl): array => [
                'id' => 'cached-url-' . $cachedUrl->id,
                'state' => __('capell-html-cache::dashboard.cached'),
                'url' => $cachedUrl->url,
                'site' => (string) ($cachedUrl->site?->name ?? __('capell-html-cache::dashboard.not_available')),
                'hits' => __('capell-html-cache::dashboard.not_tracked'),
                'last_hit' => __('capell-html-cache::dashboard.not_tracked'),
                'cached_at' => $this->dateForHumans($cachedUrl->getAttribute('cached_at')),
                'last_seen' => $this->dateForHumans($cachedUrl->getAttribute('last_seen_at')),
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

    private function dateForHumans(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->diffForHumans();
        }

        if (is_string($value) && $value !== '') {
            return CarbonImmutable::parse($value)->diffForHumans();
        }

        return __('capell-html-cache::dashboard.never');
    }
}
