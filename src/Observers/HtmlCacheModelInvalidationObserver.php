<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Observers;

use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Translation;
use Capell\HtmlCache\Actions\ClearCachedUrlsForModelAction;
use Capell\HtmlCache\Actions\MarkCachedUrlsForModelStaleAction;
use Illuminate\Database\Eloquent\Model;

final class HtmlCacheModelInvalidationObserver
{
    /** @var array<class-string<Model>, true>|null */
    private static ?array $capellModelClasses = null;

    /**
     * @param  array<int, mixed>  $payload
     */
    public function createdFromEvent(string $eventName, array $payload): void
    {
        $model = $this->modelFromPayload($payload);

        if ($model instanceof Model) {
            $this->created($model);
        }
    }

    public function created(Model $model): void
    {
        if ($this->isExcludedRouteModel($model)) {
            return;
        }

        if (! $this->shouldInvalidateForModel($model)) {
            return;
        }

        $this->dispatchClearCachedUrlsForModel($model);
    }

    /**
     * @param  array<int, mixed>  $payload
     */
    public function updatedFromEvent(string $eventName, array $payload): void
    {
        $model = $this->modelFromPayload($payload);

        if ($model instanceof Model) {
            $this->updated($model);
        }
    }

    public function updated(Model $model): void
    {
        if (! $this->shouldInvalidateForModel($model)) {
            return;
        }

        if ($this->isTimestampOnlyUpdate($model)) {
            return;
        }

        $this->dispatchClearCachedUrlsForModel($model);
    }

    /**
     * @param  array<int, mixed>  $payload
     */
    public function deletedFromEvent(string $eventName, array $payload): void
    {
        $model = $this->modelFromPayload($payload);

        if ($model instanceof Model) {
            $this->deleted($model);
        }
    }

    public function deleted(Model $model): void
    {
        if ($this->isExcludedRouteModel($model)) {
            return;
        }

        if (! $this->shouldInvalidateForModel($model)) {
            return;
        }

        $this->dispatchClearCachedUrlsForModel($model);
    }

    /**
     * @param  array<int, mixed>  $payload
     */
    private function modelFromPayload(array $payload): ?Model
    {
        $model = $payload[0] ?? null;

        return $model instanceof Model ? $model : null;
    }

    private function shouldInvalidateForModel(Model $model): bool
    {
        if ($model instanceof Page || $model instanceof PageUrl) {
            return false;
        }

        if ($model instanceof Translation) {
            return true;
        }

        return isset($this->capellModelClasses()[$model::class]);
    }

    private function isExcludedRouteModel(Model $model): bool
    {
        return $model instanceof Page || $model instanceof PageUrl;
    }

    /**
     * @return array<class-string<Model>, true>
     */
    private function capellModelClasses(): array
    {
        if (self::$capellModelClasses !== null) {
            return self::$capellModelClasses;
        }

        self::$capellModelClasses = [];

        foreach (CapellCore::getModels() as $modelClass) {
            if (! is_subclass_of($modelClass, Model::class)) {
                continue;
            }

            /** @var class-string<Model> $modelClass */
            self::$capellModelClasses[$modelClass] = true;
        }

        return self::$capellModelClasses;
    }

    private function dispatchClearCachedUrlsForModel(Model $model): void
    {
        $morphClass = $model->getMorphClass();
        $modelKey = $this->integerModelKey($model);

        if ($modelKey === null) {
            return;
        }

        if (config('capell-html-cache.invalidation.mode', 'instant') === 'scheduled') {
            if (app()->runningUnitTests() || app()->runningInConsole()) {
                MarkCachedUrlsForModelStaleAction::dispatchSync($morphClass, $modelKey);

                return;
            }

            MarkCachedUrlsForModelStaleAction::dispatchAfterResponse($morphClass, $modelKey);

            return;
        }

        if (app()->runningUnitTests() || app()->runningInConsole()) {
            ClearCachedUrlsForModelAction::dispatchSync($morphClass, $modelKey);

            return;
        }

        ClearCachedUrlsForModelAction::dispatchAfterResponse($morphClass, $modelKey);
    }

    private function integerModelKey(Model $model): ?int
    {
        $modelKey = $model->getKey();

        return is_numeric($modelKey) ? (int) $modelKey : null;
    }

    private function isTimestampOnlyUpdate(Model $model): bool
    {
        $changedAttributes = array_keys($model->getChanges());

        return $changedAttributes !== []
            && array_diff($changedAttributes, [$model->getUpdatedAtColumn()]) === [];
    }
}
