<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Filament\Widgets;

use Capell\Admin\Contracts\CapellWidgetContract;
use Capell\Admin\Filament\Concerns\GatedByRoleAndSettings;
use Capell\HtmlCache\Actions\Dashboard\BuildHtmlCacheStaleQueueRowsAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Collection;

final class HtmlCacheStaleQueueWidget extends BaseWidget implements CapellWidgetContract
{
    use GatedByRoleAndSettings;

    /** @var list<string> */
    protected static array $rolesConfigKeys = ['admin', 'super_admin'];

    protected static string $settingsKey = 'html_cache_stale_queue';

    /** @var int|string|array<string, int|string|null> */
    protected int|string|array $columnSpan = ['default' => 'full', 'xl' => 1];

    protected static ?int $sort = 53;

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): Collection => BuildHtmlCacheStaleQueueRowsAction::run(5))
            ->queryStringIdentifier('html-cache-stale-queue')
            ->paginated(false)
            ->searchable(false)
            ->heading(__('capell-html-cache::dashboard.stale_queue'))
            ->columns([
                TextColumn::make('url')
                    ->label(__('capell-html-cache::dashboard.url'))
                    ->limit(60)
                    ->wrap(),
                TextColumn::make('status')
                    ->label(__('capell-html-cache::dashboard.status')),
                TextColumn::make('attempts')
                    ->label(__('capell-html-cache::dashboard.attempts'))
                    ->numeric(),
                TextColumn::make('reason')
                    ->label(__('capell-html-cache::dashboard.reason'))
                    ->limit(30),
                TextColumn::make('updated')
                    ->label(__('capell-html-cache::dashboard.updated')),
            ]);
    }
}
