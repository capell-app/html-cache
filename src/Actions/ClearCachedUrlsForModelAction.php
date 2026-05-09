<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\HtmlCache\Models\CachedModelUrl;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsJob;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static int run(Model $model, bool $refresh = false)
 */
final class ClearCachedUrlsForModelAction
{
    use AsJob;
    use AsObject;

    public bool $jobDeleteWhenMissingModels = true;

    public function handle(Model $model, bool $refresh = false): int
    {
        $urls = CachedModelUrl::query()
            ->where('cacheable_type', $model->getMorphClass())
            ->where('cacheable_id', (int) $model->getKey())
            ->pluck('url')
            ->unique()
            ->values();

        $cleared = 0;

        foreach ($urls as $url) {
            if (is_string($url) && ClearCachedUrlAction::run($url, refresh: $refresh)) {
                $cleared++;
            }
        }

        return $cleared;
    }

    public function getJobUniqueId(Model $model): string
    {
        return 'clear-cached-model-urls-' . $model->getMorphClass() . '-' . $model->getKey();
    }
}
