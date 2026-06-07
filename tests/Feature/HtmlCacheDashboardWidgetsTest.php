<?php

declare(strict_types=1);

use Capell\Admin\Contracts\DashboardSettingsContributor;
use Capell\Admin\Enums\DashboardEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\HtmlCache\Actions\Dashboard\BuildHtmlCacheDashboardStatsAction;
use Capell\HtmlCache\Actions\Dashboard\BuildHtmlCacheStaleQueueRowsAction;
use Capell\HtmlCache\Actions\Dashboard\BuildHtmlCacheUrlRowsAction;
use Capell\HtmlCache\Filament\Settings\Contributors\HtmlCacheDashboardSettingsContributor;
use Capell\HtmlCache\Filament\Widgets\CacheCoverageUrlsWidget;
use Capell\HtmlCache\Filament\Widgets\HtmlCacheOverviewWidget;
use Capell\HtmlCache\Filament\Widgets\HtmlCacheStaleQueueWidget;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Models\StaleCachedUrl;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Livewire\Livewire;

uses(HtmlCacheTestCase::class);

/**
 * @param  Collection<array-key, mixed>  $assignedSiteIds
 */
function createHtmlCacheDashboardWidgetUser(SupportCollection $assignedSiteIds): Authenticatable
{
    $user = new class extends Authenticatable implements FilamentUser
    {
        /** @use HasFactory<Factory<static>> */
        use HasFactory;

        /** @var SupportCollection<int, int> */
        public SupportCollection $assignedSiteIds;

        protected $table = 'users';

        public function canAccessPanel(Panel $panel): bool
        {
            return true;
        }

        /** @return SupportCollection<int, int> */
        public function getAssignedSiteIds(): SupportCollection
        {
            return $this->assignedSiteIds;
        }

        public function isGlobalAdmin(): bool
        {
            return false;
        }
    };

    $user->forceFill([
        'name' => 'HTML Cache Dashboard User',
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
    ]);
    $user->assignedSiteIds = $assignedSiteIds;

    return $user;
}

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
    Livewire::test($widgetClass)->assertSuccessful();
})->with([
    HtmlCacheOverviewWidget::class,
    CacheCoverageUrlsWidget::class,
    HtmlCacheStaleQueueWidget::class,
]);

it('builds HTML Cache overview and URL rows from scoped cache data', function (): void {
    $language = Language::factory()->create();
    $secondLanguage = Language::factory()->create();
    $site = Site::factory()->language($language)->withTranslations($language)->create();
    $hiddenSite = Site::factory()->language($language)->withTranslations($language)->create();
    $cachedPage = Page::factory()->site($site)->withTranslations($language)->create();
    $secondLanguageCachedPage = Page::factory()->site($site)->withTranslations($secondLanguage)->create();
    $uncachedPage = Page::factory()->site($site)->withTranslations($language)->create();
    $hiddenPage = Page::factory()->site($hiddenSite)->withTranslations($language)->create();

    test()->actingAs(createHtmlCacheDashboardWidgetUser(collect([$site->getKey()])));
    PageUrl::query()->delete();

    PageUrl::factory()->page($cachedPage)->site($site)->language($language)->state([
        'url' => '/cached',
        'hit_count' => 10,
        'last_hit_at' => now()->subHour(),
    ])->create();
    PageUrl::factory()->page($uncachedPage)->site($site)->language($language)->state([
        'url' => '/uncached',
        'hit_count' => 30,
        'last_hit_at' => now()->subMinutes(5),
    ])->create();
    PageUrl::factory()->page($hiddenPage)->site($hiddenSite)->language($language)->state([
        'url' => '/hidden',
        'hit_count' => 99,
        'last_hit_at' => now(),
    ])->create();

    foreach ([1, 2] as $dependencyId) {
        CachedModelUrl::query()->create([
            'url' => 'https://example.com/cached',
            'url_hash' => hash('sha256', 'https://example.com/cached'),
            'path' => '/cached',
            'site_id' => $site->getKey(),
            'language_id' => $language->getKey(),
            'cacheable_type' => Page::class,
            'cacheable_id' => $dependencyId === 1 ? $cachedPage->getKey() : $uncachedPage->getKey(),
            'cached_at' => now()->subHours(2),
            'last_seen_at' => now()->subHour(),
            'hit_count' => 10,
            'bytes_served' => 1200,
            'last_hit_at' => now()->subMinutes(30),
        ]);
    }

    CachedModelUrl::query()->create([
        'url' => 'https://example.com/cached',
        'url_hash' => hash('sha256', 'https://example.com/cached'),
        'path' => '/cached',
        'site_id' => $site->getKey(),
        'language_id' => $secondLanguage->getKey(),
        'cacheable_type' => Page::class,
        'cacheable_id' => $secondLanguageCachedPage->getKey(),
        'cached_at' => now()->subHours(3),
        'last_seen_at' => now()->subHours(2),
    ]);
    CachedModelUrl::query()->create([
        'url' => 'https://hidden.test/hidden',
        'url_hash' => hash('sha256', 'https://hidden.test/hidden'),
        'path' => '/hidden',
        'site_id' => $hiddenSite->getKey(),
        'language_id' => $language->getKey(),
        'cacheable_type' => Page::class,
        'cacheable_id' => $hiddenPage->getKey(),
        'cached_at' => now(),
        'last_seen_at' => now(),
    ]);

    $stats = BuildHtmlCacheDashboardStatsAction::run();
    $rows = BuildHtmlCacheUrlRowsAction::run('coverage', 6);
    $lastRow = $rows->last();

    throw_unless(is_array($lastRow), RuntimeException::class, 'Expected cache traffic row to exist.');

    expect($stats->pageUrls)->toBe(2)
        ->and($stats->cachedPageUrls)->toBe(1)
        ->and($stats->uncachedPageUrls)->toBe(1)
        ->and($stats->coverageRate)->toBe(50.0)
        ->and($stats->trackedCachedUrls)->toBe(2)
        ->and($stats->cachedTrafficCoverageRate)->toBe(25.0)
        ->and($rows->pluck('url')->all())->toBe([
            '/uncached',
            'https://example.com/cached',
            'https://example.com/cached',
        ])
        ->and($lastRow['hits'])->toBe('10')
        ->and($lastRow['last_hit'])->not->toBe(__('capell-html-cache::dashboard.not_tracked'));
});

it('builds stale queue rows with translated states from scoped rows', function (): void {
    $site = Site::factory()->withTranslations()->create();
    $hiddenSite = Site::factory()->withTranslations()->create();

    test()->actingAs(createHtmlCacheDashboardWidgetUser(collect([$site->getKey()])));

    StaleCachedUrl::query()->create([
        'url' => 'https://example.com/failing',
        'url_hash' => hash('sha256', 'https://example.com/failing'),
        'path' => '/failing',
        'stale_key' => StaleCachedUrl::staleKey(hash('sha256', 'https://example.com/failing'), (int) $site->getKey(), null, '/failing'),
        'site_id' => $site->getKey(),
        'status' => StaleCachedUrl::STATUS_FAILED,
        'reason' => 'page_saved',
        'attempts' => 2,
    ]);
    StaleCachedUrl::query()->create([
        'url' => 'https://hidden.test/failing',
        'url_hash' => hash('sha256', 'https://hidden.test/failing'),
        'path' => '/failing',
        'stale_key' => StaleCachedUrl::staleKey(hash('sha256', 'https://hidden.test/failing'), (int) $hiddenSite->getKey(), null, '/failing'),
        'site_id' => $hiddenSite->getKey(),
        'status' => StaleCachedUrl::STATUS_FAILED,
        'attempts' => 1,
    ]);

    $rows = BuildHtmlCacheStaleQueueRowsAction::run();
    $firstRow = $rows->first();

    throw_unless(is_array($firstRow), RuntimeException::class, 'Expected stale queue row to exist.');

    expect($rows)->toHaveCount(1)
        ->and($firstRow['status'])->toBe(__('capell-html-cache::dashboard.status_failed'))
        ->and($firstRow['url'])->toBe('https://example.com/failing');
});
