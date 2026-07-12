<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Filament\Settings\Contributors;

use Capell\Admin\Contracts\DashboardSettingsContributor;

final class HtmlCacheDashboardSettingsContributor implements DashboardSettingsContributor
{
    /**
     * @return list<array{key: string, label: string, group: string}>
     */
    public function settingsKeys(): array
    {
        return [
            [
                'key' => 'html_cache_overview',
                'label' => __('capell-html-cache::dashboard.html_cache_overview'),
                'group' => __('capell-html-cache::dashboard.group'),
            ],
            [
                'key' => 'html_cache_coverage_urls',
                'label' => __('capell-html-cache::dashboard.cache_coverage_urls'),
                'group' => __('capell-html-cache::dashboard.group'),
            ],
            [
                'key' => 'html_cache_stale_queue',
                'label' => __('capell-html-cache::dashboard.stale_queue'),
                'group' => __('capell-html-cache::dashboard.group'),
            ],
        ];
    }
}
