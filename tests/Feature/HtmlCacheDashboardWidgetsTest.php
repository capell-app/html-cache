<?php

declare(strict_types=1);

use Capell\Admin\Contracts\DashboardSettingsContributor;
use Capell\Admin\Enums\DashboardEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\HtmlCache\Filament\Settings\Contributors\HtmlCacheDashboardSettingsContributor;
use Capell\HtmlCache\Filament\Widgets\CacheCoverageUrlsWidget;
use Capell\HtmlCache\Filament\Widgets\HtmlCacheOverviewWidget;
use Capell\HtmlCache\Filament\Widgets\HtmlCacheStaleQueueWidget;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Livewire\Livewire;

uses(HtmlCacheTestCase::class);

it('exposes HTML Cache dashboard settings keys with translated labels', function (): void {
    $entries = (new HtmlCacheDashboardSettingsContributor)->settingsKeys();

    expect(collect($entries)->pluck('key')->all())->toBe([
        'html_cache_overview',
        'html_cache_coverage_urls',
        'html_cache_stale_queue',
    ]);

    foreach ($entries as $entry) {
        expect($entry['label'])->toBeString()->not->toBe('')
            ->and(str_contains($entry['label'], 'capell-html-cache::'))->toBeFalse()
            ->and($entry['group'])->toBeString()->not->toBe('');
    }
});

it('registers HTML Cache dashboard widgets and settings contributor', function (): void {
    $contributors = collect(app()->tagged(DashboardSettingsContributor::TAG))
        ->map(fn (DashboardSettingsContributor $contributor): string => $contributor::class);

    expect($contributors)->toContain(HtmlCacheDashboardSettingsContributor::class)
        ->and(CapellAdmin::getDashboardWidgets(DashboardEnum::Main))
        ->toContain(HtmlCacheOverviewWidget::class)
        ->toContain(CacheCoverageUrlsWidget::class)
        ->toContain(HtmlCacheStaleQueueWidget::class);
});

it('renders HTML Cache dashboard widgets', function (string $widgetClass): void {
    Livewire::test($widgetClass)->assertOk();
})->with([
    HtmlCacheOverviewWidget::class,
    CacheCoverageUrlsWidget::class,
    HtmlCacheStaleQueueWidget::class,
]);
