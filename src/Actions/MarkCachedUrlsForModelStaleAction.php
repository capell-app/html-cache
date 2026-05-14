<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\HtmlCache\Models\CachedModelUrl;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsJob;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static int run(Model|string $model, int|string|null $modelKey = null, string $reason = 'model_changed')
 */
final class MarkCachedUrlsForModelStaleAction
{
    use AsJob;
    use AsObject;

    public bool $jobDeleteWhenMissingModels = true;

    public function handle(Model|string $model, int|string|null $modelKey = null, string $reason = 'model_changed'): int
    {
        [$morphClass, $key] = $this->modelIdentifier($model, $modelKey);

        $cachedModelUrls = CachedModelUrl::query()
            ->with('siteDomain')
            ->where('cacheable_type', $morphClass)
            ->where('cacheable_id', $key)
            ->orderBy('id')
            ->lazyById();

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
}
