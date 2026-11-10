<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Observers;

use Capell\Core\Enums\PublishVisibilityStateEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Core\Octane\Resettable;
use Capell\Core\Support\TableName;
use Capell\HtmlCache\Actions\ClearAllHtmlCacheAction;
use Capell\HtmlCache\Actions\ClearCachedUrlsForModelAction;
use Capell\HtmlCache\Actions\MarkCachedUrlsForModelStaleAction;
use Capell\HtmlCache\Actions\MarkCachedUrlsForSiteStaleAction;
use Capell\HtmlCache\Actions\WithdrawCachedUrlsForModelAction;
use Illuminate\Database\Eloquent\Model;

final class HtmlCacheModelInvalidationObserver implements Resettable
{
    /** @var array<class-string<Model>, true>|null */
    private ?array $capellModelClasses = null;

    public function flushOctaneState(): void
    {
        $this->capellModelClasses = null;
    }

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
        if ($this->requiresImmediateGlobalInvalidation($model)) {
            ClearAllHtmlCacheAction::dispatch()->afterCommit();

            return;
        }

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
        if ($this->requiresImmediateGlobalInvalidation($model)) {
            ClearAllHtmlCacheAction::dispatch()->afterCommit();

            return;
        }

        if ($this->withdrawsSiteWideContent($model)) {
            ClearAllHtmlCacheAction::dispatch()->afterCommit();

            return;
        }

        if ($model instanceof Page && $model->wasChanged(['visible_from', 'visible_until'])) {
            if (! $this->isPublic($model)) {
                // Withdrawal: a stale-marked file stays readable by the web
                // server, so the page's URLs and its dependants are removed.
                $this->dispatchWithdrawModel($model);

                return;
            }

            if (! $this->isPublic($this->previousState($model))) {
                // Publication adds the page to listings and menus that do not
                // record it yet, so their dependants cannot be targeted.
                ClearAllHtmlCacheAction::dispatch()->afterCommit();

                return;
            }
        }

        if ($model instanceof Site && $model->wasChanged('theme_id')) {
            $this->dispatchMarkSiteCachedUrlsStale($model);

            return;
        }

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
        if ($this->requiresImmediateGlobalInvalidation($model) || $model instanceof Site) {
            ClearAllHtmlCacheAction::dispatch()->afterCommit();

            return;
        }

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
        if ($model instanceof PageUrl) {
            return false;
        }

        if ($model instanceof Page) {
            return true;
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
        if ($this->capellModelClasses !== null) {
            return $this->capellModelClasses;
        }

        $this->capellModelClasses = [];

        foreach (CapellCore::getModels() as $modelClass) {
            if (! is_subclass_of($modelClass, Model::class)) {
                continue;
            }

            /** @var class-string<Model> $modelClass */
            $this->capellModelClasses[$modelClass] = true;
        }

        return $this->capellModelClasses;
    }

    private function dispatchClearCachedUrlsForModel(Model $model): void
    {
        $morphClass = $model->getMorphClass();
        $modelKey = $this->integerModelKey($model);

        if ($modelKey === null) {
            return;
        }

        if (config('capell-html-cache.invalidation.mode', 'instant') === 'scheduled') {
            MarkCachedUrlsForModelStaleAction::dispatch($morphClass, $modelKey)->afterCommit();

            return;
        }

        ClearCachedUrlsForModelAction::dispatch($morphClass, $modelKey)->afterCommit();
    }

    private function integerModelKey(Model $model): ?int
    {
        $modelKey = $model->getKey();

        return is_numeric($modelKey) ? (int) $modelKey : null;
    }

    private function dispatchMarkSiteCachedUrlsStale(Site $site): void
    {
        $siteId = $this->integerModelKey($site);

        if ($siteId === null) {
            return;
        }

        MarkCachedUrlsForSiteStaleAction::dispatch($siteId)->afterCommit();
    }

    private function isTimestampOnlyUpdate(Model $model): bool
    {
        $changedAttributes = array_keys($model->getChanges());

        return $changedAttributes !== []
            && array_diff($changedAttributes, [$model->getUpdatedAtColumn()]) === [];
    }

    private function requiresImmediateGlobalInvalidation(Model $model): bool
    {
        return in_array($model->getTable(), [TableName::resolve('access_gate_areas'), TableName::resolve('access_gate_site_access_policies')], true);
    }

    /**
     * Updates that stop serving content across a whole site. Scheduled mode
     * stale-marks ordinary edits, but a stale file is still served by the web
     * server, so these remove every origin copy and rotate the publication
     * generation so an in-flight render cannot restore them. Blueprints are
     * shared by sites, themes, pages, widgets and navigation, so the pages a
     * blueprint change withdraws are not recorded anywhere to target.
     */
    private function withdrawsSiteWideContent(Model $model): bool
    {
        return match (true) {
            $model instanceof Site => $model->wasChanged('status'),
            default => $model->getTable() === TableName::resolve('blueprints') && $model->wasChanged('status'),
        };
    }

    private function isPublic(Page $page): bool
    {
        return $page->publishVisibilityState() === PublishVisibilityStateEnum::published;
    }

    private function previousState(Page $page): Page
    {
        return $page->newInstance()->setRawAttributes([...$page->getAttributes(), ...$page->getPrevious()]);
    }

    private function dispatchWithdrawModel(Model $model): void
    {
        $modelKey = $this->integerModelKey($model);

        if ($modelKey === null) {
            ClearAllHtmlCacheAction::dispatch()->afterCommit();

            return;
        }

        WithdrawCachedUrlsForModelAction::dispatch($model->getMorphClass(), $modelKey)->afterCommit();
    }
}
