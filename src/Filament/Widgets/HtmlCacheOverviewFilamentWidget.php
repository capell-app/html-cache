<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Filament\Widgets;

use Capell\Admin\Contracts\CapellFilamentWidgetContract;
use Capell\Admin\Filament\Concerns\GatedByRoleAndSettings;
use Capell\HtmlCache\Actions\Dashboard\BuildHtmlCacheDashboardStatsAction;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;
use Override;

final class HtmlCacheOverviewFilamentWidget extends StatsOverviewWidget implements CapellFilamentWidgetContract
{
    use GatedByRoleAndSettings;

    /** @var list<string> */
    protected static array $rolesConfigKeys = ['admin', 'super_admin'];

    protected static string $settingsKey = 'html_cache_overview';

    /** @var int|string|array<string, int|null> */
    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 50;

    #[Override]
    protected function getStats(): array
    {
        $stats = BuildHtmlCacheDashboardStatsAction::run();

        return [
            Stat::make(__('capell-html-cache::dashboard.cache_coverage'), (string) Number::format($stats->coverageRate, precision: 1, locale: app()->getLocale()) . '%')
                ->description(__('capell-html-cache::dashboard.cache_coverage_description', [
                    'cached' => (string) Number::format($stats->cachedPageUrls, precision: 0, locale: app()->getLocale()),
                    'total' => (string) Number::format($stats->pageUrls, precision: 0, locale: app()->getLocale()),
                ])),
            Stat::make(__('capell-html-cache::dashboard.uncached_urls'), (string) Number::format($stats->uncachedPageUrls, precision: 0, locale: app()->getLocale())),
            Stat::make(__('capell-html-cache::dashboard.tracked_cached_urls'), (string) Number::format($stats->trackedCachedUrls, precision: 0, locale: app()->getLocale())),
            Stat::make(__('capell-html-cache::dashboard.cached_traffic_coverage'), (string) Number::format($stats->cachedTrafficCoverageRate, precision: 1, locale: app()->getLocale()) . '%'),
            Stat::make(__('capell-html-cache::dashboard.pending_regeneration'), (string) Number::format($stats->stalePending, precision: 0, locale: app()->getLocale())),
            Stat::make(__('capell-html-cache::dashboard.failed_regeneration'), (string) Number::format($stats->staleFailed, precision: 0, locale: app()->getLocale())),
        ];
    }
}
