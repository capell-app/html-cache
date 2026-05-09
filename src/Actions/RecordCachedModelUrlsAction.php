<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Core\Actions\LoadSiteDomainFromUrlAction;
use Capell\Core\Models\SiteDomain;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static void run(string $url, array $models)
 */
final class RecordCachedModelUrlsAction
{
    use AsObject;

    /**
     * @param  array<string, array<int, int|string>>  $models
     */
    public function handle(string $url, array $models): void
    {
        if ($url === '' || $models === []) {
            return;
        }

        $resolved = LoadSiteDomainFromUrlAction::run($url);
        $siteDomain = is_array($resolved) && ($resolved[0] ?? null) instanceof SiteDomain ? $resolved[0] : null;
        $path = is_array($resolved) && is_string($resolved[1] ?? null)
            ? $resolved[1]
            : resolve(HtmlCachePathResolver::class)->normalizePathFromUrl($url);
        $urlHash = CachedModelUrl::hashUrl($url);

        $seenKeys = [];
        $now = now();

        DB::transaction(function () use ($models, $url, $urlHash, $path, $siteDomain, $now, &$seenKeys): void {
            foreach ($models as $cacheableType => $ids) {
                if ($cacheableType === '' || $ids === []) {
                    continue;
                }

                foreach (array_unique(array_map('intval', $ids)) as $cacheableId) {
                    if ($cacheableId <= 0) {
                        continue;
                    }

                    $seenKeys[] = $cacheableType . ':' . $cacheableId;

                    CachedModelUrl::query()->updateOrCreate(
                        [
                            'url_hash' => $urlHash,
                            'cacheable_type' => $cacheableType,
                            'cacheable_id' => $cacheableId,
                        ],
                        [
                            'url' => $url,
                            'path' => $path,
                            'site_id' => $siteDomain?->site_id,
                            'site_domain_id' => $siteDomain?->getKey(),
                            'language_id' => $siteDomain?->language_id,
                            'cached_at' => $now,
                            'last_seen_at' => $now,
                        ],
                    );
                }
            }

            CachedModelUrl::query()
                ->where('url_hash', $urlHash)
                ->get()
                ->each(function (CachedModelUrl $cachedModelUrl) use ($seenKeys): void {
                    $key = $cachedModelUrl->cacheable_type . ':' . $cachedModelUrl->cacheable_id;

                    if (! in_array($key, $seenKeys, true)) {
                        $cachedModelUrl->delete();
                    }
                });
        });
    }
}
