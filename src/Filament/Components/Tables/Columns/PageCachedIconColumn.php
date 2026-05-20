<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Filament\Components\Tables\Columns;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\PageUrl;
use Capell\HtmlCache\Actions\DeletePageCacheAction;
use Capell\HtmlCache\Models\CachedModelUrl;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Illuminate\Support\Facades\Date;

final class PageCachedIconColumn extends IconColumn
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::table.page_cached'))
            ->alignCenter()
            ->getStateUsing(fn (Pageable|PageUrl $record): ?CachedModelUrl => $this->getCachedPage($record))
            ->icon(fn (?CachedModelUrl $state): string => $state instanceof CachedModelUrl ? 'heroicon-c-check' : 'heroicon-c-x-mark')
            ->color(fn (?CachedModelUrl $state): string => $state instanceof CachedModelUrl ? 'info' : 'gray')
            ->tooltip(function (?CachedModelUrl $state): ?string {
                if (! $state instanceof CachedModelUrl || $state->last_seen_at === null) {
                    return null;
                }

                $time = Date::instance($state->last_seen_at);

                return __(
                    'capell-admin::generic.page_cached_tooltip',
                    ['time' => $time->translatedFormat($this->getTable()->getDefaultDateDisplayFormat())],
                );
            })
            ->action($this->deleteCacheFile(...));
    }

    private function deleteCacheFile(Pageable|PageUrl $record): void
    {
        DeletePageCacheAction::dispatch($record);

        $count = $record instanceof Pageable ? $record->pageUrls()->count() : 1;

        if ($count !== 0) {
            Notification::make('page_cache_deleted')
                ->title(__('capell-admin::message.page_cache_deleted', ['count' => $count]))
                ->success()
                ->send();
        }
    }

    private function getCachedPage(Pageable|PageUrl $record): ?CachedModelUrl
    {
        if ($record instanceof PageUrl) {
            return $this->cachedModelUrlForPageUrl($record);
        }

        foreach ($record->pageUrls as $url) {
            if ($url instanceof PageUrl) {
                $cachedModelUrl = $this->cachedModelUrlForPageUrl($url);

                if ($cachedModelUrl instanceof CachedModelUrl) {
                    return $cachedModelUrl;
                }
            }
        }

        return null;
    }

    private function cachedModelUrlForPageUrl(PageUrl $pageUrl): ?CachedModelUrl
    {
        if ($pageUrl->siteDomain === null) {
            return null;
        }

        return CachedModelUrl::query()
            ->where('site_domain_id', $pageUrl->siteDomain->getKey())
            ->where('path', $pageUrl->url)
            ->latest('last_seen_at')
            ->first();
    }
}
