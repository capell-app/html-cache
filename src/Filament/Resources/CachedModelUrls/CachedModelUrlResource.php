<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Filament\Resources\CachedModelUrls;

use BackedEnum;
use Capell\HtmlCache\Filament\Resources\CachedModelUrls\Pages\ListCachedModelUrls;
use Capell\HtmlCache\Filament\Resources\CachedModelUrls\Tables\CachedModelUrlsTable;
use Capell\HtmlCache\Models\CachedModelUrl;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Override;

final class CachedModelUrlResource extends Resource
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentMagnifyingGlass;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::DocumentMagnifyingGlass;

    protected static ?string $recordTitleAttribute = 'url';

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return CachedModelUrlsTable::configure($table);
    }

    #[Override]
    public static function getModel(): string
    {
        return CachedModelUrl::class;
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('capell-html-cache::admin.cached_model_urls');
    }

    public static function getNavigationGroup(): ?string
    {
        return (string) __('capell-html-cache::admin.navigation_group');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCachedModelUrls::route('/'),
        ];
    }
}
