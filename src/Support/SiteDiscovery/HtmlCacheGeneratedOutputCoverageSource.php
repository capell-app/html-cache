<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\SiteDiscovery;

use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\SiteDiscovery\Contracts\GeneratedOutputCoverageSource;
use Capell\SiteDiscovery\Data\PublicUrlRegistryEntryData;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

final class HtmlCacheGeneratedOutputCoverageSource implements GeneratedOutputCoverageSource
{
    public function key(): string
    {
        return GeneratedOutputCoverageSource::HTML_CACHE;
    }

    /**
     * @param  Collection<int, PublicUrlRegistryEntryData>  $registryEntries
     * @return Collection<int, string>
     */
    public function coveredUrls(Collection $registryEntries): Collection
    {
        if (! Schema::hasTable((new CachedModelUrl)->getTable())) {
            return collect();
        }

        return CachedModelUrl::query()
            ->select('url')
            ->distinct()
            ->pluck('url')
            ->map(fn (mixed $url): ?string => $this->normalizeCoveredUrl($url))
            ->filter(fn (?string $url): bool => $url !== null)
            ->values();
    }

    private function normalizeCoveredUrl(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        return $url;
    }
}
