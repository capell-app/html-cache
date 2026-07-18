<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Models\StaleCachedUrl;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsJob;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static int run(Model|string $model, int|string|null $modelKey = null, string $reason = 'model_changed')
 */
final class MarkCachedUrlsForModelStaleAction
{
    use AsFake;
    use AsJob;
    use AsObject;

    public bool $jobDeleteWhenMissingModels = true;

    public function handle(Model|string $model, int|string|null $modelKey = null, string $reason = 'model_changed'): int
    {
        [$morphClass, $key] = $this->modelIdentifier($model, $modelKey);

        $cachedModelUrls = CachedModelUrl::query()
            ->with('siteDomain')
            ->select('cached_model_urls.*')
            ->where('cacheable_type', $morphClass)
            ->where('cacheable_id', $key)
            ->orderBy('cached_model_urls.id')
            ->lazyById(column: 'cached_model_urls.id', alias: 'id');

        $marked = 0;
        $rows = [];
        $pathResolver = resolve(HtmlCachePathResolver::class);

        foreach ($cachedModelUrls as $cachedModelUrl) {
            $staleKey = StaleCachedUrl::staleKey(
                $cachedModelUrl->url_hash,
                $cachedModelUrl->site_id,
                $cachedModelUrl->site_domain_id,
                $cachedModelUrl->path,
            );

            $siteDomain = $cachedModelUrl->siteDomain;
            $cachePath = $siteDomain === null ? null : $pathResolver->pathForRequestUrl($cachedModelUrl->url, $siteDomain);
            $errorCachePath = $siteDomain === null ? null : $pathResolver->pathForRequestUrl($cachedModelUrl->url, $siteDomain, error: true);

            $rows[] = $this->staleUrlRow(
                staleKey: $staleKey,
                cachedModelUrl: $cachedModelUrl,
                cachePath: $cachePath,
                errorCachePath: $errorCachePath,
                reason: $reason,
            );
            $marked++;

            if (count($rows) >= 500) {
                $this->upsertStaleUrls($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            $this->upsertStaleUrls($rows);
        }

        return $marked;
    }

    public function getJobUniqueId(Model|string $model, int|string|null $modelKey = null): string
    {
        [$morphClass, $key] = $this->modelIdentifier($model, $modelKey);

        return 'mark-stale-cached-model-urls-' . $morphClass . '-' . $key;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function modelIdentifier(Model|string $model, int|string|null $modelKey): array
    {
        if ($model instanceof Model) {
            return [$model->getMorphClass(), (int) $model->getKey()];
        }

        return [$model, (int) $modelKey];
    }

    /**
     * @return array<string, mixed>
     */
    private function staleUrlRow(
        string $staleKey,
        CachedModelUrl $cachedModelUrl,
        ?string $cachePath,
        ?string $errorCachePath,
        string $reason,
    ): array {
        $now = CarbonImmutable::now();

        return [
            'stale_key' => $staleKey,
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
