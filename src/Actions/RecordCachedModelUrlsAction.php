<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Core\Actions\LoadSiteDomainFromUrlAction;
use Capell\Core\Models\SiteDomain;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static void run(string $url, array<string, array<int, int|string>> $models, ?CarbonInterface $seenAt = null)
 *
 * @phpstan-type CachedModelUrlRecord array{
 *     url_hash: string,
 *     cacheable_type: string,
 *     cacheable_id: int,
 *     url: string,
 *     path: string,
 *     site_id: int|null,
 *     site_domain_id: int|string|null,
 *     language_id: int|null,
 *     cached_at: CarbonImmutable,
 *     last_seen_at: CarbonImmutable
 * }
 */
final class RecordCachedModelUrlsAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<string, array<int, int|string>>  $models
     */
    public function handle(string $url, array $models, ?CarbonInterface $seenAt = null): void
    {
        if ($url === '' || $models === []) {
            return;
        }

        $resolved = LoadSiteDomainFromUrlAction::run($url);
        $siteDomain = is_array($resolved) ? $resolved[0] : null;
        $path = is_array($resolved)
            ? $resolved[1]
            : resolve(HtmlCachePathResolver::class)->normalizePathFromUrl($url);
        $urlHash = CachedModelUrl::hashUrl($url);
        $now = $seenAt?->toImmutable() ?? CarbonImmutable::now();

        DB::transaction(function () use ($models, $url, $urlHash, $path, $siteDomain, $now): void {
            $records = $this->records(
                models: $models,
                url: $url,
                urlHash: $urlHash,
                path: $path,
                siteDomain: $siteDomain,
                seenAt: $now,
            );
            $existing = $this->existingRecords($urlHash, $records);
            $upserts = array_values(array_filter(
                $records,
                function (array $record) use ($existing, $now): bool {
                    $cachedModelUrl = $existing->get($this->recordKey($record));

                    return ! ($cachedModelUrl instanceof CachedModelUrl
                        && $cachedModelUrl->last_seen_at instanceof CarbonInterface
                        && $cachedModelUrl->last_seen_at->greaterThan($now));
                },
            ));

            if ($upserts !== []) {
                CachedModelUrl::query()->upsert(
                    $upserts,
                    ['url_hash', 'cacheable_type', 'cacheable_id'],
                    [
                        'url' => $url,
                        'path' => $path,
                        'site_id' => $siteDomain?->site_id,
                        'site_domain_id' => $siteDomain?->id,
                        'language_id' => $siteDomain?->language_id,
                        'cached_at' => $now,
                        'last_seen_at' => $now,
                    ],
                );
            }

            $seenKeys = array_fill_keys(array_map($this->recordKey(...), $records), true);
            $staleIds = CachedModelUrl::query()
                ->where('url_hash', $urlHash)
                ->where('last_seen_at', '<=', $now)
                ->get(['id', 'cacheable_type', 'cacheable_id'])
                ->reject(fn (CachedModelUrl $cachedModelUrl): bool => isset($seenKeys[$this->cachedModelUrlKey($cachedModelUrl)]))
                ->modelKeys();

            if ($staleIds !== []) {
                CachedModelUrl::query()
                    ->where('url_hash', $urlHash)
                    ->where('last_seen_at', '<=', $now)
                    ->whereKey($staleIds)
                    ->delete();
            }
        }, attempts: 5);
    }

    /**
     * @param  array<string, array<int, int|string>>  $models
     * @return list<CachedModelUrlRecord>
     */
    private function records(
        array $models,
        string $url,
        string $urlHash,
        string $path,
        ?SiteDomain $siteDomain,
        CarbonImmutable $seenAt,
    ): array {
        $records = [];

        foreach ($models as $cacheableType => $ids) {
            if ($cacheableType === '' || $ids === []) {
                continue;
            }

            foreach (array_unique(array_map(intval(...), $ids)) as $cacheableId) {
                if ($cacheableId <= 0) {
                    continue;
                }

                $records[] = [
                    'url_hash' => $urlHash,
                    'cacheable_type' => $cacheableType,
                    'cacheable_id' => $cacheableId,
                    'url' => $url,
                    'path' => $path,
                    'site_id' => $siteDomain?->site_id,
                    'site_domain_id' => $siteDomain?->id,
                    'language_id' => $siteDomain?->language_id,
                    'cached_at' => $seenAt,
                    'last_seen_at' => $seenAt,
                ];
            }
        }

        return $records;
    }

    /**
     * @param  list<CachedModelUrlRecord>  $records
     * @return Collection<string, CachedModelUrl>
     */
    private function existingRecords(string $urlHash, array $records): Collection
    {
        if ($records === []) {
            return collect();
        }

        return CachedModelUrl::query()
            ->where('url_hash', $urlHash)
            ->where(function (Builder $query) use ($records): void {
                foreach ($records as $index => $record) {
                    $constraint = static function (Builder $query) use ($record): void {
                        $query
                            ->where('cacheable_type', $record['cacheable_type'])
                            ->where('cacheable_id', $record['cacheable_id']);
                    };

                    if ($index === 0) {
                        $query->where($constraint);
                    } else {
                        $query->orWhere($constraint);
                    }
                }
            })
            ->get(['cacheable_type', 'cacheable_id', 'last_seen_at'])
            ->keyBy($this->cachedModelUrlKey(...));
    }

    /** @param CachedModelUrlRecord $record */
    private function recordKey(array $record): string
    {
        return $record['cacheable_type'] . ':' . $record['cacheable_id'];
    }

    private function cachedModelUrlKey(CachedModelUrl $cachedModelUrl): string
    {
        return $cachedModelUrl->cacheable_type . ':' . $cachedModelUrl->cacheable_id;
    }
}
