<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsJob;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static int run(Model|string $model, int|string|null $modelKey = null, bool $refresh = false)
 */
final class ClearCachedUrlsForModelAction
{
    use AsFake;
    use AsJob;
    use AsObject;

    public bool $jobDeleteWhenMissingModels = true;

    public function handle(Model|string $model, int|string|null $modelKey = null, bool $refresh = false): int
    {
        $urls = ResolveCachedUrlsForModelAction::run($model, $modelKey);

        $cleared = 0;

        foreach ($urls as $url) {
            if (ClearCachedUrlAction::run($url, refresh: $refresh)) {
                $cleared++;
            }
        }

        return $cleared;
    }

    public function getJobUniqueId(Model|string $model, int|string|null $modelKey = null): string
    {
        [$morphClass, $key] = $this->modelIdentifier($model, $modelKey);

        return 'clear-cached-model-urls-' . $morphClass . '-' . $key;
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
