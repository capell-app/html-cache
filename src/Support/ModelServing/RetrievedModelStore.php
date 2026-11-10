<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\ModelServing;

use Capell\Frontend\Contracts\CapturesRenderedModelIdentities;
use Capell\Frontend\Data\RenderedModelIdentitiesData;
use Capell\HtmlCache\Actions\RecordCachedModelUrlsAction;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Jobs\RegisterCachedModelUrlsJob;
use Capell\HtmlCache\Support\Cache\HtmlCachePublicationGuard;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Override;
use Throwable;

final class RetrievedModelStore implements CapturesRenderedModelIdentities
{
    /** @var array<string, array<int, int|string>> */
    private array $retrievedModels = [];

    /**
     * Open identity captures, innermost last. A capture collects every model
     * tracked while it is open, including models the store already holds.
     *
     * @var list<array<string, list<string>>>
     */
    private array $captures = [];

    #[Override]
    public function track(Model $model): void
    {
        $visited = [];
        $this->collectRecursive($model, $visited);
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    #[Override]
    public function trackByClass(Model $model, string $modelClass): void
    {
        $visited = [];
        $this->collectRecursive($model, $visited, (new $modelClass)->getMorphClass());
    }

    #[Override]
    public function tracked(string $modelType): int
    {
        return count($this->retrievedModels[$modelType] ?? []);
    }

    #[Override]
    public function startCapturingIdentities(): void
    {
        $this->captures[] = [];
    }

    #[Override]
    public function stopCapturingIdentities(): RenderedModelIdentitiesData
    {
        return new RenderedModelIdentitiesData(array_pop($this->captures) ?? []);
    }

    /**
     * A render served from cached data retrieves none of the models it shows,
     * so the identities its first render captured are recorded in their place.
     */
    #[Override]
    public function trackIdentities(RenderedModelIdentitiesData $identities): void
    {
        foreach ($identities->keysByType as $modelType => $modelIds) {
            foreach ($modelIds as $modelId) {
                $this->addIdentity($modelType, $modelId);
            }
        }
    }

    /** @return list<string> keys in HtmlCachePublicationGuard::modelKey() form */
    public function modelKeys(): array
    {
        $keys = [];

        foreach ($this->retrievedModels as $modelType => $modelIds) {
            foreach ($modelIds as $modelId) {
                $keys[] = HtmlCachePublicationGuard::modelKey($modelType, $modelId);
            }
        }

        return $keys;
    }

    public function flushToUrl(string $url): void
    {
        if ($url === '' || $this->retrievedModels === []) {
            $this->retrievedModels = [];

            return;
        }

        if ($this->isSyntheticRender()) {
            RecordCachedModelUrlsAction::run($url, $this->retrievedModels, CarbonImmutable::now());
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

            defer(static function () use ($url, $retrievedModels, $seenAt): void {
                try {
                    dispatch_sync(new RegisterCachedModelUrlsJob($url, $retrievedModels, $seenAt));
                } catch (Throwable $throwable) {
                    Log::warning('Unable to record cached model URLs after rendering.', [
                        'url' => $url,
                        'model_types' => array_keys($retrievedModels),
                        'exception' => $throwable::class,
                        'message' => $throwable->getMessage(),
                    ]);
                    RegisterCachedModelUrlsJob::withdrawUnrecordedUrl($url);
                }
            });
        } elseif ($mode === 'async') {
            dispatch(new RegisterCachedModelUrlsJob($url, $this->retrievedModels, CarbonImmutable::now()));
        } else {
            dispatch_sync(new RegisterCachedModelUrlsJob($url, $this->retrievedModels, CarbonImmutable::now()));
        }

        $this->retrievedModels = [];
    }

    private function isSyntheticRender(): bool
    {
        try {
            $request = resolve('request');
        } catch (Throwable) {
            return false;
        }

        return $request instanceof Request
            && $request->attributes->get(HtmlCacheMiddleware::SYNTHETIC_RENDER_ATTRIBUTE) === true;
    }

    /**
     * @param  array<string, bool>  $visited
     */
    private function collectRecursive(Model $model, array &$visited, ?string $overrideModelType = null): void
    {
        if ($model instanceof MorphPivot) {
            return;
        }

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
        $this->addIdentity($modelType, (string) $model->getKey());
    }

    private function addIdentity(string $modelType, string $modelId): void
    {
        $this->retrievedModels[$modelType] ??= [];

        if (! in_array($modelId, $this->retrievedModels[$modelType], true)) {
            $this->retrievedModels[$modelType][] = $modelId;
        }

        foreach (array_keys($this->captures) as $index) {
            if (! in_array($modelId, $this->captures[$index][$modelType] ?? [], true)) {
                $this->captures[$index][$modelType][] = $modelId;
            }
        }
    }
}
