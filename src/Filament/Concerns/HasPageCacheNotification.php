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

/**
 * @mixin EditRecord
 * @mixin RelationManager
 */
trait HasPageCacheNotification
{
    #[On('refresh-cache')]
    public function refreshPageCache(?array $urls = null): void
    {
        if ($urls !== null && $urls !== []) {
            foreach ($urls as $url) {
                if (is_string($url)) {
                    ClearCachedUrlAction::dispatchAfterResponse($url);
                }
            }
        } else {
            ClearAllHtmlCacheAction::run();
        }

        CapellCore::flushCache();

        Notification::make('page-cache-refreshed')
            ->title(__('capell-admin::notification.page_cache_refreshed'))
            ->icon('heroicon-o-check-circle')
            ->iconColor('success')
            ->send();

        $this->dispatch('close-notification', id: 'clear-page-cache');
    }

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
