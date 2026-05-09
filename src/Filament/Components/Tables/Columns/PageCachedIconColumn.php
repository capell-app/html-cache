<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Filament\Components\Tables\Columns;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\PageUrl;
use Capell\HtmlCache\Actions\DeletePageCacheAction;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Capell\HtmlCache\Support\Cache\HtmlCacheStore;
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
            ->getStateUsing(fn (Pageable|PageUrl $record): ?string => $this->getCachedPage($record))
            ->icon(fn (?string $state): string => blank($state) ? 'heroicon-c-x-mark' : 'heroicon-c-check')
            ->color(fn (?string $state): string => blank($state) ? 'gray' : 'info')
            ->tooltip(function (?string $state): ?string {
                if (blank($state)) {
                    return null;
                }

                $state = str_replace(['../', '..\\'], '', $state);
                $store = resolve(HtmlCacheStore::class);

                if (! $store->exists($state)) {
                    return null;
                }

                $lastModified = $store->lastModified($state);

                if ($lastModified === null) {
                    return null;
                }

                $time = Date::createFromTimestamp($lastModified);

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

    private function getCachedPage(Pageable|PageUrl $record): ?string
    {
        if ($record instanceof PageUrl) {
            return $this->cacheFileForPageUrl($record);
        }

        foreach ($record->pageUrls as $url) {
            if ($url instanceof PageUrl) {
                $cacheFile = $this->cacheFileForPageUrl($url);

                if ($cacheFile !== null && $cacheFile !== '') {
                    return $cacheFile;
                }
            }
        }

        return null;
    }

    private function cacheFileForPageUrl(PageUrl $pageUrl): ?string
    {
        if ($pageUrl->siteDomain === null) {
            return null;
        }

        $cacheFile = resolve(HtmlCachePathResolver::class)->pathForUrl($pageUrl->url, $pageUrl->siteDomain);

        return resolve(HtmlCacheStore::class)->exists($cacheFile) ? $cacheFile : null;
    }
}
