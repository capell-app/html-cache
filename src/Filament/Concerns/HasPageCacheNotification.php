<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Filament\Concerns;

use Capell\Core\Facades\CapellCore;
use Capell\HtmlCache\Actions\ClearAllHtmlCacheAction;
use Capell\HtmlCache\Actions\ClearCachedUrlAction;
use Capell\HtmlCache\Actions\ClearCachedUrlsForModelAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;
use RuntimeException;
use Throwable;

/**
 * @mixin EditRecord
 * @mixin RelationManager
 */
trait HasPageCacheNotification
{
    /**
     * @param  array<int, string>|null  $urls
     */
    #[On('refresh-cache')]
    public function refreshPageCache(?array $urls = null): void
    {
        try {
            if ($urls !== null && $urls !== []) {
                foreach ($urls as $url) {
                    if (! is_string($url) || ! ClearCachedUrlAction::run($url)) {
                        throw new RuntimeException('Unable to clear a requested HTML cache URL.');
                    }
                }
            } else {
                ClearAllHtmlCacheAction::run();
            }

            CapellCore::flushCache();
        } catch (Throwable $throwable) {
            report($throwable);

            Notification::make('page-cache-refresh-failed')
                ->title(__('capell-html-cache::admin.clear_failed'))
                ->danger()
                ->send();

            return;
        }

        Notification::make('page-cache-refreshed')
            ->title(__('capell-admin::notification.page_cache_refreshed'))
            ->icon('heroicon-o-check-circle')
            ->iconColor('success')
            ->send();

        $this->dispatch('close-notification', id: 'clear-page-cache');
    }

    /**
     * @param  array<int, mixed>|Model  $models
     */
    public function notifyPageCached(array|Model $models): void
    {
        if (! is_array($models)) {
            $models = [$models];
        }

        foreach ($models as $model) {
            if ($model instanceof Model) {
                ClearCachedUrlsForModelAction::run($model);
            }
        }
    }
}
