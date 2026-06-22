<?php

declare(strict_types=1);

use Capell\Core\Enums\PageTypeEnum;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Frontend\Contracts\StaticMaintenancePageStore;
use Capell\HtmlCache\Actions\EnsureHtmlCachePermissionsAction;
use Capell\HtmlCache\Enums\HtmlCachePermission;
use Capell\HtmlCache\Filament\Extenders\Site\MaintenanceSiteHeaderActionExtender;
use Capell\HtmlCache\Filament\Pages\MaintenanceCachePage;
use Capell\HtmlCache\Support\Maintenance\HtmlCacheStaticMaintenancePageStore;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(HtmlCacheTestCase::class);

it('registers the frontend static maintenance page store against the page cache disk', function (): void {
    Storage::fake('page_cache');

    $store = resolve(StaticMaintenancePageStore::class);
    $store->put('maintenance/https.example.test/index.html', '<h1>Maintenance</h1>');

    expect($store)->toBeInstanceOf(HtmlCacheStaticMaintenancePageStore::class)
        ->and(Storage::disk('page_cache')->get('maintenance/https.example.test/index.html'))->toBe('<h1>Maintenance</h1>');
});

it('gates maintenance cache administration to global actors or maintenance managers', function (): void {
    expect(MaintenanceCachePage::canAccess())->toBeFalse();

    test()->actingAs(User::factory()->create());
    expect(MaintenanceCachePage::canAccess())->toBeFalse();

    EnsureHtmlCachePermissionsAction::run();

    $maintenanceManager = User::factory()->create();
    $maintenanceManager->givePermissionTo(HtmlCachePermission::ManageMaintenance->value);

    test()->actingAs($maintenanceManager);
    expect(MaintenanceCachePage::canAccess())->toBeTrue();
});

it('renders the maintenance cache page with the frontend manifest store import', function (): void {
    EnsureHtmlCachePermissionsAction::run();

    $maintenanceManager = User::factory()->create();
    $maintenanceManager->givePermissionTo(HtmlCachePermission::ManageMaintenance->value);

    test()->actingAs($maintenanceManager);

    Livewire::test(MaintenanceCachePage::class)
        ->assertSuccessful()
        ->assertSee(__('capell-html-cache::admin.manifest_path'));
});

it('does not create system pages while building site header actions', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    test()->actingAs($admin);

    $site = Site::factory()->create();

    $systemPageCount = fn (): int => Page::query()
        ->where('site_id', $site->id)
        ->whereHas('type', fn (Builder $query): Builder => $query->whereIn('key', [
            PageTypeEnum::NotFound->value,
            PageTypeEnum::Maintenance->value,
        ]))
        ->count();

    // Site creation may already provision a static error (NotFound) page via the
    // frontend error-page regeneration observer; that is unrelated to the header
    // action extender under test. Assert the extender itself creates no new pages.
    $countBeforeActions = $systemPageCount();

    resolve(MaintenanceSiteHeaderActionExtender::class)->actions();

    expect($systemPageCount())->toBe($countBeforeActions);
});
