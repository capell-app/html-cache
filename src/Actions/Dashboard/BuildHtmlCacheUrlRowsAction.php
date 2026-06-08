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
            ->map($this->uncachedRow(...))
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
            ->selectRaw('MAX(hit_count) as hit_count')
            ->selectRaw('MAX(bytes_served) as bytes_served')
            ->selectRaw('MAX(last_hit_at) as last_hit_at')
            ->groupBy('url_hash', 'url', 'site_id', 'language_id')
            ->latest('last_hit_at')
            ->latest('last_seen_at')
            ->limit($limit)
            ->get()
            ->map($this->cachedRow(...))
            ->values();
    }

    /**
     * @return array{id: string, state: string, url: string, site: string, hits: string, last_hit: string, cached_at: string, last_seen: string}
     */
    private function uncachedRow(PageUrl $pageUrl): array
    {
        return [
            'id' => 'uncached-page-url-' . $pageUrl->id,
            'state' => (string) __('capell-html-cache::dashboard.uncached'),
            'url' => $pageUrl->url,
            'site' => $pageUrl->site->name ?? (string) __('capell-html-cache::dashboard.not_available'),
            'hits' => number_format($pageUrl->hit_count),
            'last_hit' => $pageUrl->last_hit_at?->diffForHumans() ?? (string) __('capell-html-cache::dashboard.never'),
            'cached_at' => (string) __('capell-html-cache::dashboard.not_cached'),
            'last_seen' => $pageUrl->last_hit_at?->diffForHumans() ?? (string) __('capell-html-cache::dashboard.never'),
        ];
    }

    /**
     * @return array{id: string, state: string, url: string, site: string, hits: string, last_hit: string, cached_at: string, last_seen: string}
     */
    private function cachedRow(CachedModelUrl $cachedUrl): array
    {
        return [
            'id' => 'cached-url-' . $cachedUrl->id,
            'state' => (string) __('capell-html-cache::dashboard.cached'),
            'url' => $cachedUrl->url,
            'site' => (string) ($cachedUrl->site->name ?? __('capell-html-cache::dashboard.not_available')),
            'hits' => number_format((int) $cachedUrl->getAttribute('hit_count')),
            'last_hit' => $this->dateForHumans($cachedUrl->getAttribute('last_hit_at')),
            'cached_at' => $this->dateForHumans($cachedUrl->getAttribute('cached_at')),
            'last_seen' => $this->dateForHumans($cachedUrl->getAttribute('last_seen_at')),
        ];
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
