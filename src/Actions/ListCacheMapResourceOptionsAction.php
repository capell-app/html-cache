<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Admin\Support\SiteScope;
use Capell\HtmlCache\Data\CacheMap\CacheMapResourceSummaryData;
use Capell\HtmlCache\Models\CachedModelUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static array<array-key, mixed> run(string $modelType, ?int $siteId = null, ?string $search = null, int $limit = 5)
 */
final class ListCacheMapResourceOptionsAction
{
    use AsFake;
    use AsObject;

    /**
     * @return list<CacheMapResourceSummaryData>
     */
    public function handle(string $modelType, ?int $siteId = null, ?string $search = null, int $limit = 5): array
    {
        /** @var Builder<CachedModelUrl> $query */
        $query = SiteScope::applyForCurrentActor(CachedModelUrl::query(), denyWhenMissingActor: true)
            ->where('cacheable_type', $modelType);

        if ($siteId !== null) {
            $query->where('site_id', $siteId);
        }

        $search = is_string($search) ? trim($search) : '';

        $resourceQuery = $query
            ->select('cacheable_type', 'cacheable_id')
            ->selectRaw('COUNT(*) as dependency_count')
            ->selectRaw('COUNT(DISTINCT url_hash) as url_count')
            ->groupBy('cacheable_type', 'cacheable_id')
            ->orderByDesc('url_count')
            ->orderByDesc('dependency_count')
            ->orderBy('cacheable_id')
            ->with('cacheable');

        if ($search === '') {
            $resourceQuery->limit(max(1, $limit));
        }

        /** @var EloquentCollection<int, CachedModelUrl> $rows */
        $rows = $resourceQuery->get();

        return array_values($rows
            ->map(fn (CachedModelUrl $row): CacheMapResourceSummaryData => new CacheMapResourceSummaryData(
                key: $this->resourceKey($row->cacheable_type, $row->cacheable_id),
                modelType: $row->cacheable_type,
                modelLabel: class_basename($row->cacheable_type),
                resourceId: $row->cacheable_id,
                label: $row->cacheableLabel(),
                dependencyCount: (int) $row->getAttribute('dependency_count'),
                urlCount: (int) $row->getAttribute('url_count'),
            ))
            ->filter(fn (CacheMapResourceSummaryData $resource): bool => $this->matchesSearch($resource, $search))
            ->take($limit)
            ->values()
            ->all());
    }

    private function resourceKey(string $modelType, int $resourceId): string
    {
        return base64_encode($modelType . '|' . $resourceId);
    }

    private function matchesSearch(CacheMapResourceSummaryData $resource, string $search): bool
    {
        if ($search === '') {
            return true;
        }

        return str_contains(strtolower($resource->label), strtolower($search))
            || str_contains((string) $resource->resourceId, $search);
    }
}
