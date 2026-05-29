<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Filament\Resources\CachedModelUrls\Tables;

use Capell\Admin\Support\SiteScope;
use Capell\HtmlCache\Actions\ClearCachedUrlAction;
use Capell\HtmlCache\Enums\HtmlCachePermission;
use Capell\HtmlCache\Models\CachedModelUrl;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

final class CachedModelUrlsTable
{
    /**
     * @param  Builder<CachedModelUrl>|null  $query
     */
    public static function configure(Table $table, ?Builder $query = null, bool $isSiteScoped = false, bool $showFilters = true): Table
    {
        if ($query instanceof Builder) {
            $table = $table->query($query);
        }

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['cacheable', 'language', 'site', 'siteDomain']))
            ->defaultSort('last_seen_at', 'desc')
            ->emptyStateHeading($isSiteScoped
                ? __('capell-html-cache::admin.cache_map_empty_selected_site')
                : __('capell-html-cache::admin.cache_map_empty'))
            ->columns([
                TextColumn::make('url')
                    ->label(__('capell-html-cache::admin.url'))
                    ->searchable(query: self::applyUrlHashSearch(...))
                    ->copyable()
                    ->limit(80)
                    ->wrap(),
                TextColumn::make('cacheable_type')
                    ->label(__('capell-html-cache::admin.model'))
                    ->searchable()
                    ->formatStateUsing(fn (string $state): string => class_basename($state)),
                TextColumn::make('cacheable')
                    ->label(__('capell-html-cache::admin.resource'))
                    ->state(fn (CachedModelUrl $record): string => $record->cacheableLabel())
                    ->description(fn (CachedModelUrl $record): string => sprintf(
                        '%s #%s',
                        class_basename($record->cacheable_type),
                        (string) $record->cacheable_id,
                    )),
                TextColumn::make('cacheable_id')
                    ->label(__('capell-html-cache::admin.model_id'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('site.name')
                    ->label(__('capell-html-cache::admin.site'))
                    ->toggleable(),
                TextColumn::make('language.name')
                    ->label(__('capell-html-cache::admin.language'))
                    ->toggleable(),
                TextColumn::make('path')
                    ->label(__('capell-html-cache::admin.path'))
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cached_at')
                    ->label(__('capell-html-cache::admin.cached_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('last_seen_at')
                    ->label(__('capell-html-cache::admin.last_seen'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters($showFilters ? self::filters($isSiteScoped) : [])
            ->recordActions([
                Action::make('open_url')
                    ->label(__('capell-html-cache::admin.open_url_new_tab'))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->extraAttributes(fn (CachedModelUrl $record): array => [
                        'aria-label' => (string) __('capell-html-cache::admin.open_url_aria_label', [
                            'url' => $record->url,
                        ]),
                    ])
                    ->url(fn (CachedModelUrl $record): string => $record->url, true),
                Action::make('clear')
                    ->label(__('capell-html-cache::admin.clear_url'))
                    ->icon('heroicon-o-trash')
                    ->extraAttributes(fn (CachedModelUrl $record): array => [
                        'aria-label' => (string) __('capell-html-cache::admin.clear_url_aria_label', [
                            'url' => $record->url,
                        ]),
                    ])
                    ->authorize(fn (CachedModelUrl $record): bool => self::canClearCachedUrl($record))
                    ->visible(fn (CachedModelUrl $record): bool => self::canClearCachedUrl($record))
                    ->requiresConfirmation()
                    ->modalHeading(__('capell-html-cache::admin.clear_url_confirmation_heading'))
                    ->modalDescription(fn (CachedModelUrl $record): string => (string) __('capell-html-cache::admin.clear_url_confirmation_description', [
                        'url' => $record->url,
                    ]))
                    ->action(function (CachedModelUrl $record, Component $livewire): void {
                        if (! $record->exists) {
                            Notification::make('html-cache-url-clear-stale')
                                ->title(__('capell-html-cache::admin.clear_url_stale'))
                                ->icon('heroicon-o-exclamation-triangle')
                                ->iconColor('warning')
                                ->send();

                            return;
                        }

                        $recordKey = (string) $record->getKey();
                        $cleared = ClearCachedUrlAction::run($record);

                        if ($cleared && method_exists($livewire, 'rememberClearedCacheMapRecordKey')) {
                            $livewire->rememberClearedCacheMapRecordKey($recordKey);
                        }

                        Notification::make($cleared ? 'html-cache-url-cleared' : 'html-cache-url-clear-incomplete')
                            ->title($cleared
                                ? __('capell-html-cache::admin.clear_url_success')
                                : __('capell-html-cache::admin.clear_url_incomplete'))
                            ->icon($cleared ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-triangle')
                            ->iconColor($cleared ? 'success' : 'warning')
                            ->send();
                    }),
            ]);
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private static function applyUrlHashSearch(Builder $query, string $search): Builder
    {
        return $query->where('url_hash', CachedModelUrl::hashUrl($search));
    }

    /**
     * @return array<int, SelectFilter>
     */
    private static function filters(bool $isSiteScoped): array
    {
        $filters = [];

        if (! $isSiteScoped) {
            $filters[] = SelectFilter::make('site_id')
                ->label(__('capell-html-cache::admin.site'))
                ->relationship(
                    'site',
                    'name',
                    fn (Builder $query): Builder => SiteScope::applyForCurrentActor($query, 'id', denyWhenMissingActor: true),
                );
        }

        $filters[] = SelectFilter::make('language_id')
            ->label(__('capell-html-cache::admin.language'))
            ->relationship('language', 'name');

        $filters[] = SelectFilter::make('cacheable_type')
            ->label(__('capell-html-cache::admin.model'))
            ->options(fn (): array => CachedModelUrl::query()
                ->tap(fn (Builder $query): Builder => SiteScope::applyForCurrentActor($query, denyWhenMissingActor: true))
                ->select('cacheable_type')
                ->distinct()
                ->orderBy('cacheable_type')
                ->pluck('cacheable_type', 'cacheable_type')
                ->map(fn (string $type): string => class_basename($type))
                ->all())
            ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null)
                ? $query
                : $query->where('cacheable_type', $data['value']));

        return $filters;
    }

    private static function canClearCachedUrl(CachedModelUrl $record): bool
    {
        $actor = auth()->user();

        if (! $actor instanceof Authenticatable) {
            return false;
        }

        if (SiteScope::isGlobalActor($actor)) {
            return true;
        }

        try {
            $canClearCacheMap = $actor->hasPermissionTo(HtmlCachePermission::ClearCachedModelUrls->value) === true;
        } catch (PermissionDoesNotExist) {
            $canClearCacheMap = false;
        }

        return $canClearCacheMap && ($record->site === null || SiteScope::actorCanUseSite($actor, $record->site));
    }
}
