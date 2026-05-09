<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Filament\Resources\CachedModelUrls\Tables;

use Capell\HtmlCache\Actions\ClearCachedUrlAction;
use Capell\HtmlCache\Models\CachedModelUrl;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class CachedModelUrlsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('last_seen_at', 'desc')
            ->columns([
                TextColumn::make('url')
                    ->label(__('capell-html-cache::admin.url'))
                    ->searchable()
                    ->copyable()
                    ->limit(80),
                TextColumn::make('cacheable_type')
                    ->label(__('capell-html-cache::admin.model'))
                    ->searchable()
                    ->formatStateUsing(fn (string $state): string => class_basename($state)),
                TextColumn::make('cacheable_id')
                    ->label(__('capell-html-cache::admin.model_id'))
                    ->searchable(),
                TextColumn::make('site.name')
                    ->label(__('capell-html-cache::admin.site'))
                    ->toggleable(),
                TextColumn::make('language.name')
                    ->label(__('capell-html-cache::admin.language'))
                    ->toggleable(),
                TextColumn::make('last_seen_at')
                    ->label(__('capell-html-cache::admin.last_seen'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('site_id')
                    ->label(__('capell-html-cache::admin.site'))
                    ->relationship('site', 'name'),
                SelectFilter::make('language_id')
                    ->label(__('capell-html-cache::admin.language'))
                    ->relationship('language', 'name'),
                SelectFilter::make('cacheable_type')
                    ->label(__('capell-html-cache::admin.model'))
                    ->options(fn (): array => CachedModelUrl::query()
                        ->select('cacheable_type')
                        ->distinct()
                        ->orderBy('cacheable_type')
                        ->pluck('cacheable_type', 'cacheable_type')
                        ->map(fn (string $type): string => class_basename($type))
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null)
                        ? $query
                        : $query->where('cacheable_type', $data['value'])),
            ])
            ->recordActions([
                Action::make('clear')
                    ->label(__('capell-html-cache::admin.clear_url'))
                    ->icon('heroicon-o-trash')
                    ->requiresConfirmation()
                    ->action(fn (CachedModelUrl $record): bool => ClearCachedUrlAction::run($record->url)),
            ]);
    }
}
