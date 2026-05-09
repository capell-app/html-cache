<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Filament\Extenders;

use Capell\Admin\Contracts\Extenders\PageTableExtender;
use Capell\HtmlCache\Filament\Components\Tables\Columns\PageCachedIconColumn;
use Filament\Actions\BulkAction;
use Filament\Tables\Columns\Column;
use Filament\Tables\Filters\BaseFilter;
use Illuminate\Database\Eloquent\Builder;

final class PageCachePageTableExtender implements PageTableExtender
{
    /**
     * @return array<int, Column>
     */
    public function getColumns(): array
    {
        return [
            PageCachedIconColumn::make('cached')
                ->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    /**
     * @return array<int, BulkAction>
     */
    public function getBulkActions(): array
    {
        return [];
    }

    /**
     * @return array<int, BaseFilter>
     */
    public function getFilters(): array
    {
        return [];
    }

    public function modifyQuery(Builder $query): Builder
    {
        return $query;
    }
}
