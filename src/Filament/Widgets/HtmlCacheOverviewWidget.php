<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Filament\Widgets;

use Capell\Admin\Contracts\CapellWidgetContract;
use Capell\Admin\Filament\Concerns\GatedByRoleAndSettings;
use Capell\HtmlCache\Actions\Dashboard\BuildHtmlCacheDashboardStatsAction;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

final class HtmlCacheOverviewWidget extends StatsOverviewWidget implements CapellWidgetContract
{
    use GatedByRoleAndSettings;

    /** @var list<string> */
    protected static array $rolesConfigKeys = ['admin', 'super_admin'];

    protected static string $settingsKey = 'html_cache_overview';

    /** @var int|string|array<string, int|null> */
    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 50;

    protected function getStats(): array
    {
        $stats = BuildHtmlCacheDashboardStatsAction::run();

        return [
            Stat::make(__('capell-html-cache::dashboard.cache_coverage'), number_format($stats->coverageRate, 1) . '%')
                ->description(__('capell-html-cache::dashboard.cache_coverage_description', [
                    'cached' => number_format($stats->cachedPageUrls),
                    'total' => number_format($stats->pageUrls),
                ])),
            Stat::make(__('capell-html-cache::dashboard.uncached_urls'), number_format($stats->uncachedPageUrls)),
            Stat::make(__('capell-html-cache::dashboard.tracked_cached_urls'), number_format($stats->trackedCachedUrls)),
            Stat::make(__('capell-html-cache::dashboard.cached_traffic_coverage'), number_format($stats->cachedTrafficCoverageRate, 1) . '%'),
            Stat::make(__('capell-html-cache::dashboard.pending_regeneration'), number_format($stats->stalePending)),
            Stat::make(__('capell-html-cache::dashboard.failed_regeneration'), number_format($stats->staleFailed)),
        ];
    }
}
