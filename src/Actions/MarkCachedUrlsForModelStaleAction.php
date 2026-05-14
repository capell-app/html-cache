<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\HtmlCache\Models\CachedModelUrl;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsJob;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static int run(Model $model, string $reason = 'model_changed')
 */
final class MarkCachedUrlsForModelStaleAction
{
    use AsJob;
    use AsObject;

    public bool $jobDeleteWhenMissingModels = true;

    public function handle(Model $model, string $reason = 'model_changed'): int
    {
        $cachedModelUrls = CachedModelUrl::query()
            ->with('siteDomain')
            ->where('cacheable_type', $model->getMorphClass())
            ->where('cacheable_id', (int) $model->getKey())
            ->get();

        $marked = 0;
        $seenKeys = [];

        foreach ($cachedModelUrls as $cachedModelUrl) {
            $uniqueKey = implode('|', [
                $cachedModelUrl->url_hash,
                $cachedModelUrl->site_id ?? 'site:any',
                $cachedModelUrl->site_domain_id ?? 'domain:any',
                $cachedModelUrl->path,
            ]);

            if (isset($seenKeys[$uniqueKey])) {
                continue;
            }

            $seenKeys[$uniqueKey] = true;
            $marked += MarkCachedUrlStaleAction::run($cachedModelUrl, reason: $reason);
        }

        return $marked;
    }

    public function getJobUniqueId(Model $model): string
    {
        return 'mark-stale-cached-model-urls-' . $model->getMorphClass() . '-' . $model->getKey();
    }
}
