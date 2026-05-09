<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\ModelServing;

use Capell\Frontend\Contracts\RenderedModelTracker;
use Capell\HtmlCache\Jobs\RegisterCachedModelUrlsJob;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class RetrievedModelStore implements RenderedModelTracker
{
    /** @var array<string, array<int, int|string>> */
    private array $retrievedModels = [];

    public function track(Model $model): void
    {
        $visited = [];
        $this->collectRecursive($model, $visited);
    }

    /**
     * @param  class-string  $modelClass
     */
    public function trackByClass(Model $model, string $modelClass): void
    {
        $visited = [];
        $this->collectRecursive($model, $visited, (new $modelClass)->getMorphClass());
    }

    public function tracked(string $modelType): int
    {
        return count($this->retrievedModels[$modelType] ?? []);
    }

    public function flushToUrl(string $url): void
    {
        if ($url === '' || $this->retrievedModels === []) {
            $this->retrievedModels = [];

            return;
        }

        $mode = config('capell-html-cache.model_event_registration_mode');

        if (in_array($mode, [null, false, ''], true)) {
            $mode = 'deferred';
        }

        if ($mode === 'deferred') {
            $retrievedModels = $this->retrievedModels;
            $seenAt = CarbonImmutable::now();

            defer(static fn (): mixed => dispatch_sync(new RegisterCachedModelUrlsJob($url, $retrievedModels, $seenAt)));
        } elseif ($mode === 'async') {
            dispatch(new RegisterCachedModelUrlsJob($url, $this->retrievedModels, CarbonImmutable::now()));
        } else {
            dispatch_sync(new RegisterCachedModelUrlsJob($url, $this->retrievedModels, CarbonImmutable::now()));
        }

        $this->retrievedModels = [];
    }

    /**
     * @param  array<string, bool>  $visited
     */
    private function collectRecursive(Model $model, array &$visited, ?string $overrideModelType = null): void
    {
        $modelType = $overrideModelType ?? $model->getMorphClass();
        $modelKey = $model->getKey();

        if ($modelKey === null) {
            return;
        }

        $uniqueId = $modelType . ':' . $modelKey;
        if (isset($visited[$uniqueId])) {
            return;
        }

        $visited[$uniqueId] = true;
        $this->addModelToStore($model, $modelType);

        foreach ($model->getRelations() as $relation) {
            $this->processRelation($relation, $visited);
        }
    }

    /**
     * @param  array<string, bool>  $visited
     */
    private function processRelation(mixed $relation, array &$visited): void
    {
        if ($relation instanceof Model) {
            $this->collectRecursive($relation, $visited);

            return;
        }

        if (! ($relation instanceof Collection) && ! is_array($relation)) {
            return;
        }

        foreach ($relation as $related) {
            if ($related instanceof Model) {
                $this->collectRecursive($related, $visited);
            }
        }
    }

    private function addModelToStore(Model $model, string $modelType): void
    {
        $this->retrievedModels[$modelType] ??= [];
        $modelId = (string) $model->getKey();

        if (! in_array($modelId, $this->retrievedModels[$modelType], true)) {
            $this->retrievedModels[$modelType][] = $modelId;
        }
    }
}
