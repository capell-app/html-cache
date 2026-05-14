<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Filament\Widgets;

use Capell\Admin\Contracts\CapellWidgetContract;
use Capell\Admin\Filament\Concerns\GatedByRoleAndSettings;
use Capell\HtmlCache\Actions\Dashboard\BuildHtmlCacheUrlRowsAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Collection;

final class CacheCoverageUrlsWidget extends BaseWidget implements CapellWidgetContract
{
    use GatedByRoleAndSettings;

    /** @var list<string> */
    protected static array $rolesConfigKeys = ['admin', 'super_admin'];

    protected static string $settingsKey = 'html_cache_coverage_urls';

    /** @var int|string|array<string, int|string|null> */
    protected int|string|array $columnSpan = ['default' => 'full', 'xl' => 1];

    protected static ?int $sort = 51;

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): Collection => BuildHtmlCacheUrlRowsAction::run('coverage', 6))
            ->queryStringIdentifier('html-cache-coverage-urls')
            ->paginated(false)
            ->searchable(false)
            ->heading(__('capell-html-cache::dashboard.cache_coverage_urls'))
            ->emptyStateHeading(__('capell-html-cache::dashboard.no_cache_coverage_urls'))
            ->emptyStateDescription(__('capell-html-cache::dashboard.no_cache_coverage_urls_description'))
            ->columns([
                TextColumn::make('state')
                    ->label(__('capell-html-cache::dashboard.state')),
                TextColumn::make('url')
                    ->label(__('capell-html-cache::dashboard.url'))
                    ->limit(60)
                    ->tooltip(fn (mixed $state): ?string => is_string($state) && $state !== '' ? $state : null)
                    ->wrap(),
                TextColumn::make('site')
                    ->label(__('capell-html-cache::dashboard.site')),
                TextColumn::make('hits')
                    ->label(__('capell-html-cache::dashboard.page_hits')),
                TextColumn::make('last_seen')
                    ->label(__('capell-html-cache::dashboard.last_seen')),
            ]);
    }
}
