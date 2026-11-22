<?php

declare(strict_types=1);

use Capell\Core\Database\Factories\LanguageFactory;
use Capell\Core\Database\Factories\PageFactory;
use Capell\Core\Database\Factories\SiteFactory;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\Permissions\PermissionTeamContext;
use Capell\HtmlCache\Actions\GrantHtmlCacheDefaultRolePermissionsAction;
use Capell\HtmlCache\Actions\NotifyClearCachedPagesAction;
use Capell\HtmlCache\Enums\HtmlCachePermission;
use Capell\HtmlCache\Filament\Components\Tables\Columns\PageCachedIconColumn;
use Capell\HtmlCache\Filament\Concerns\HasPageCacheNotification;
use Capell\HtmlCache\Jobs\ClearAuthorisedPageCacheJob;
use Capell\HtmlCache\Livewire\SiteHealthCacheMap;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Support\HtmlCacheAuthorization;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Capell\Tests\Fixtures\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(HtmlCacheTestCase::class);

beforeEach(function (): void {
    Storage::fake('page_cache');
    // Site-scoped roles exist only when permission teams are enabled.
    config()->set('permission.teams', true);
    resolve(PermissionRegistrar::class)->teams = true;
    Queue::fake();

    foreach (HtmlCachePermission::names() as $name) {
        Permission::findOrCreate($name, 'web');
    }
});

/**
 * @return array{0: Site, 1: PageUrl, 2: Page}
 */
function htmlCacheAuthorizationSitePage(string $domain): array
{
    $language = LanguageFactory::new()->create(['code' => 'en-' . str_replace('.', '-', $domain)]);
    $site = SiteFactory::new()->language($language)->withTranslations($language, siteDomainData: [
        'scheme' => 'https', 'domain' => $domain, 'path' => '/',
    ])->create();
    $page = PageFactory::new()->site($site)->withTranslations($language)->create();
    $pageUrl = PageUrl::factory()->page($page)->site($site)->language($language)->create(['url' => '/cached']);

    return [$site, $pageUrl->fresh() ?? $pageUrl, $page->fresh() ?? $page];
}

function htmlCacheAuthorizationCachedUrl(PageUrl $pageUrl, ?string $url = null, ?string $path = null): CachedModelUrl
{
    $domain = SiteDomain::query()->where('site_id', $pageUrl->site_id)->firstOrFail();
    $url ??= $pageUrl->full_url;

    return CachedModelUrl::query()->create([
        'url' => $url,
        'url_hash' => CachedModelUrl::hashUrl($url),
        'path' => $path ?? $pageUrl->url,
        'site_id' => $pageUrl->site_id,
        'site_domain_id' => $domain->getKey(),
        'language_id' => $pageUrl->language_id,
        'cacheable_type' => $pageUrl->pageable_type,
        'cacheable_id' => $pageUrl->pageable_id,
        'cached_at' => now(),
        'last_seen_at' => now(),
    ]);
}

/**
 * A user whose site role on $site holds only the given html-cache permissions.
 *
 * @param  list<HtmlCachePermission>  $permissions
 */
function htmlCacheAuthorizationSiteUser(Site $site, array $permissions): User
{
    $user = User::factory()->create();
    $roleName = 'html-cache-site-role-' . $site->id . '-' . count($permissions);

    PermissionTeamContext::run($site->id, function () use ($user, $site, $roleName, $permissions): void {
        $role = Role::findOrCreate($roleName, 'web');
        $role->syncPermissions(array_map(fn (HtmlCachePermission $permission): string => $permission->value, $permissions));
        $user->assignRoleForSite($site, $roleName);
    }, $user);

    resolve(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->fresh() ?? $user;
}

function htmlCacheAuthorizationInvokeColumn(PageUrl|Page $record): void
{
    $column = PageCachedIconColumn::make('cached');
    $column->record($record);
    $action = $column->getAction();
    throw_unless($action instanceof Closure, RuntimeException::class, 'Expected a page cache clear callback.');
    $action($record);
}

it('hides the pages-list cache column from a page lister without cache permissions', function (): void {
    [$site] = htmlCacheAuthorizationSitePage('hidden.test');
    $lister = htmlCacheAuthorizationSiteUser($site, []);
    test()->actingAs($lister);

    PermissionTeamContext::run($site->id, function (): void {
        expect(PageCachedIconColumn::make('cached')->isHidden())->toBeTrue();
    });

    $viewer = htmlCacheAuthorizationSiteUser($site, [HtmlCachePermission::ViewCachedModelUrls]);
    test()->actingAs($viewer);

    PermissionTeamContext::run($site->id, function (): void {
        expect(PageCachedIconColumn::make('cached')->isHidden())->toBeFalse();
    });
});

it('disables the column click and refuses a direct call without the clear permission', function (): void {
    [$site, $pageUrl] = htmlCacheAuthorizationSitePage('viewer.test');
    $viewer = htmlCacheAuthorizationSiteUser($site, [HtmlCachePermission::ViewCachedModelUrls]);
    test()->actingAs($viewer);

    $column = PageCachedIconColumn::make('cached');
    $column->record($pageUrl);

    expect($column->isClickDisabled())->toBeTrue();

    htmlCacheAuthorizationInvokeColumn($pageUrl);

    Queue::assertNotPushed(ClearAuthorisedPageCacheJob::class);
    Notification::assertNotified(Notification::make()->title(__('capell-html-cache::admin.clear_not_allowed'))->danger());
});

it('refuses a clear for a page on a site the user cannot use', function (): void {
    [$site] = htmlCacheAuthorizationSitePage('own.test');
    [, $foreignPageUrl, $foreignPage] = htmlCacheAuthorizationSitePage('foreign.test');
    $clearer = htmlCacheAuthorizationSiteUser($site, [HtmlCachePermission::ClearCachedModelUrls]);
    test()->actingAs($clearer);

    htmlCacheAuthorizationInvokeColumn($foreignPageUrl);
    htmlCacheAuthorizationInvokeColumn($foreignPage);

    Queue::assertNotPushed(ClearAuthorisedPageCacheJob::class);
    expect(HtmlCacheAuthorization::canClearPage($clearer, $foreignPageUrl))->toBeFalse();
});

it('queues an attributed clear for a page on a site the user may clear', function (): void {
    [$site, $pageUrl] = htmlCacheAuthorizationSitePage('allowed.test');
    $clearer = htmlCacheAuthorizationSiteUser($site, [HtmlCachePermission::ClearCachedModelUrls]);
    test()->actingAs($clearer);

    $column = PageCachedIconColumn::make('cached');
    $column->record($pageUrl);
    expect($column->isClickDisabled())->toBeFalse();

    htmlCacheAuthorizationInvokeColumn($pageUrl);

    Queue::assertPushed(
        ClearAuthorisedPageCacheJob::class,
        fn (ClearAuthorisedPageCacheJob $job): bool => $job->actor->is($clearer) && $job->record->is($pageUrl),
    );
});

it('clears nothing when a queued clear runs after the user lost the permission', function (): void {
    [$site, $pageUrl] = htmlCacheAuthorizationSitePage('replay.test');
    $row = htmlCacheAuthorizationCachedUrl($pageUrl);
    $clearer = htmlCacheAuthorizationSiteUser($site, [HtmlCachePermission::ClearCachedModelUrls]);
    // Serialised as the queue stores it, so handle() sees freshly loaded models.
    $payload = serialize(new ClearAuthorisedPageCacheJob($clearer, $pageUrl, false));
    // A decision remembered earlier in the same process must not outlive the withdrawal.
    expect(HtmlCacheAuthorization::canClearPage($clearer, $pageUrl))->toBeTrue();

    PermissionTeamContext::run($site->id, function () use ($site): void {
        Role::findByName('html-cache-site-role-' . $site->id . '-1', 'web')
            ->revokePermissionTo(HtmlCachePermission::ClearCachedModelUrls->value);
    });
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();

    $job = unserialize($payload);
    throw_unless($job instanceof ClearAuthorisedPageCacheJob, RuntimeException::class);
    $job->handle();

    expect($row->fresh())->not->toBeNull();
});

it('clears through the job only while the user may still clear that site', function (): void {
    [$site, $pageUrl] = htmlCacheAuthorizationSitePage('job-allowed.test');
    [, $foreignPageUrl] = htmlCacheAuthorizationSitePage('job-foreign.test');
    $ownRow = htmlCacheAuthorizationCachedUrl($pageUrl);
    $foreignRow = htmlCacheAuthorizationCachedUrl($foreignPageUrl);
    $clearer = htmlCacheAuthorizationSiteUser($site, [HtmlCachePermission::ClearCachedModelUrls]);

    foreach ([$foreignPageUrl, $pageUrl] as $record) {
        $job = unserialize(serialize(new ClearAuthorisedPageCacheJob($clearer, $record, false)));
        throw_unless($job instanceof ClearAuthorisedPageCacheJob, RuntimeException::class);
        $job->handle();
    }

    expect($foreignRow->fresh())->not->toBeNull()
        ->and($ownRow->fresh())->toBeNull();
});

it('refuses refresh-cache listener calls the user may not make', function (): void {
    [$site, $pageUrl] = htmlCacheAuthorizationSitePage('listener.test');
    [, $foreignPageUrl] = htmlCacheAuthorizationSitePage('listener-foreign.test');
    $ownRow = htmlCacheAuthorizationCachedUrl($pageUrl);
    $foreignRow = htmlCacheAuthorizationCachedUrl($foreignPageUrl);
    $clearer = htmlCacheAuthorizationSiteUser($site, [HtmlCachePermission::ClearCachedModelUrls]);
    test()->actingAs($clearer);
    $component = new class
    {
        use HasPageCacheNotification;

        public function dispatch(string $event, mixed ...$params): void {}
    };

    $component->refreshPageCache();
    $component->refreshPageCache([$foreignRow->url]);
    $component->refreshPageCache([$ownRow->url, $foreignRow->url]);
    $component->refreshPageCache(['https://listener.test/never-cached']);

    expect($ownRow->fresh())->not->toBeNull()
        ->and($foreignRow->fresh())->not->toBeNull();

    $component->refreshPageCache([$ownRow->url]);

    expect($ownRow->fresh())->toBeNull()
        ->and($foreignRow->fresh())->not->toBeNull();
    Notification::assertNotified(Notification::make()->title(__('capell-html-cache::admin.clear_not_allowed'))->danger());
});

it('offers the clear prompt after a save only to users who may clear those pages', function (): void {
    [$site, $pageUrl, $page] = htmlCacheAuthorizationSitePage('prompt.test');
    htmlCacheAuthorizationCachedUrl($pageUrl);
    config(['capell-admin.auto_clear_cache' => false]);
    /** @var Collection<int, mixed> $models */
    $models = collect([$page]);

    test()->actingAs(htmlCacheAuthorizationSiteUser($site, [HtmlCachePermission::ViewCachedModelUrls]));
    NotifyClearCachedPagesAction::run($models);
    Notification::assertNotNotified(trans_choice('capell-admin::notification.detected_cached_pages', 1, ['count' => 1]));

    test()->actingAs(htmlCacheAuthorizationSiteUser($site, [HtmlCachePermission::ClearCachedModelUrls]));
    NotifyClearCachedPagesAction::run($models);
    Notification::assertNotified(trans_choice('capell-admin::notification.detected_cached_pages', 1, ['count' => 1]));
});

it('refuses a cached-row clear for a row without a site to a site-scoped user', function (): void {
    [$site, $pageUrl] = htmlCacheAuthorizationSitePage('siteless.test');
    $row = htmlCacheAuthorizationCachedUrl($pageUrl);
    $clearer = htmlCacheAuthorizationSiteUser($site, [HtmlCachePermission::ClearCachedModelUrls]);

    expect(HtmlCacheAuthorization::canClearCachedUrl($clearer, $row))->toBeTrue();

    $row->setRelation('site', null);

    // Even inside the user's own site team, a row with no site is not theirs to clear.
    PermissionTeamContext::run($site->id, function () use ($clearer, $row): void {
        expect(HtmlCacheAuthorization::canClearCachedUrl($clearer, $row))->toBeFalse();
    }, $clearer);
});

it('grants view and clear to the built-in editor and admin roles without creating roles', function (): void {
    $editor = Role::findOrCreate('editor', 'web');
    $admin = Role::findOrCreate('admin', 'web');

    GrantHtmlCacheDefaultRolePermissionsAction::run('web');

    foreach ([$editor, $admin] as $role) {
        $role = Role::query()->with('permissions')->whereKey($role->getKey())->sole();
        expect($role->hasPermissionTo(HtmlCachePermission::ViewCachedModelUrls->value))->toBeTrue()
            ->and($role->hasPermissionTo(HtmlCachePermission::ClearCachedModelUrls->value))->toBeTrue()
            ->and($role->hasPermissionTo(HtmlCachePermission::ManageMaintenance->value))->toBeFalse();
    }

    Role::query()->whereIn('name', ['editor', 'admin'])->delete();

    GrantHtmlCacheDefaultRolePermissionsAction::run('web');

    expect(Role::query()->whereIn('name', ['editor', 'admin'])->exists())->toBeFalse();
});

it('grants the default roles from the upgrade migration on existing installs', function (): void {
    $admin = Role::findOrCreate('admin', 'web');
    $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_11_12_000001_grant_html_cache_permissions_to_default_roles.php';

    $migration->up();

    expect(Role::query()->with('permissions')->whereKey($admin->getKey())->sole()->hasPermissionTo(HtmlCachePermission::ClearCachedModelUrls->value))->toBeTrue();
});

it('refuses a page with addresses on two sites when the user may clear only one', function (): void {
    [$siteA, , $page] = htmlCacheAuthorizationSitePage('every-a.test');
    [$siteB] = htmlCacheAuthorizationSitePage('every-b.test');
    // Core refuses this pairing on save; the guard must still hold for rows written around it.
    PageUrl::withoutEvents(fn (): PageUrl => PageUrl::factory()->page($page)->site($siteB)->language($siteB->language)->create(['url' => '/also-on-b']));
    $page = Page::query()->with('pageUrls.site')->whereKey($page->getKey())->sole();
    $clearer = htmlCacheAuthorizationSiteUser($siteA, [HtmlCachePermission::ClearCachedModelUrls]);
    test()->actingAs($clearer);

    expect(HtmlCacheAuthorization::canClearPage($clearer, $page))->toBeFalse();

    htmlCacheAuthorizationInvokeColumn($page);

    Queue::assertNotPushed(ClearAuthorisedPageCacheJob::class);
});

it('refuses an address whose stored rows span a site the user cannot clear', function (): void {
    [$siteA, $pageUrlA] = htmlCacheAuthorizationSitePage('moved-a.test');
    [, $pageUrlB] = htmlCacheAuthorizationSitePage('moved-b.test');
    $rowA = htmlCacheAuthorizationCachedUrl($pageUrlA);
    // The same address recorded again under site B, e.g. after a domain moved between sites.
    $rowB = htmlCacheAuthorizationCachedUrl($pageUrlB);
    $rowB->forceFill(['url' => $rowA->url, 'url_hash' => $rowA->url_hash])->save();
    $clearer = htmlCacheAuthorizationSiteUser($siteA, [HtmlCachePermission::ClearCachedModelUrls]);
    test()->actingAs($clearer);
    $component = new class
    {
        use HasPageCacheNotification;

        public function dispatch(string $event, mixed ...$params): void {}
    };

    expect(HtmlCacheAuthorization::clearableUrls($clearer, [$rowA->url]))->toBeNull();

    $component->refreshPageCache([$rowA->url]);

    expect($rowA->fresh())->not->toBeNull()
        ->and($rowB->fresh())->not->toBeNull();
});

it('pins an authorised clear to the stored row rather than the address string', function (): void {
    [$site, $pageUrl] = htmlCacheAuthorizationSitePage('pinned.test');
    $row = htmlCacheAuthorizationCachedUrl($pageUrl);
    $clearer = htmlCacheAuthorizationSiteUser($site, [HtmlCachePermission::ClearCachedModelUrls]);

    $targets = HtmlCacheAuthorization::clearableUrls($clearer, [$row->url]);

    expect($targets)->toHaveCount(1)
        ->and($targets[0] ?? null)->toBeInstanceOf(CachedModelUrl::class)
        ->and(($targets[0] ?? null) instanceof CachedModelUrl && $targets[0]->is($row))->toBeTrue();
});

it('hides the cached-pages clear action for a row with no domain from a site-scoped user', function (): void {
    [$site, $pageUrl, $page] = htmlCacheAuthorizationSitePage('table.test');
    $row = htmlCacheAuthorizationCachedUrl($pageUrl);
    $legacy = CachedModelUrl::query()->create([
        'url' => $row->url . '-legacy',
        'url_hash' => CachedModelUrl::hashUrl($row->url . '-legacy'),
        'path' => '/cached-legacy',
        'site_id' => $row->site_id,
        'site_domain_id' => null,
        'language_id' => $row->language_id,
        'cacheable_type' => $row->cacheable_type,
        'cacheable_id' => $row->cacheable_id,
        'cached_at' => now(),
        'last_seen_at' => now(),
    ]);
    $user = htmlCacheAuthorizationSiteUser($site, [HtmlCachePermission::ViewCachedModelUrls, HtmlCachePermission::ClearCachedModelUrls]);
    test()->actingAs($user);
    resolve(PermissionRegistrar::class)->setPermissionsTeamId($site->id);

    Livewire::test(SiteHealthCacheMap::class, ['siteId' => $site->id])
        ->set('selectedModelType', $page->getMorphClass())
        ->assertTableActionVisible('clear', record: (string) $row->id)
        ->assertTableActionHidden('clear', record: (string) $legacy->id);
});

it('grants only the configured built-in roles, not custom roles named admin or editor', function (): void {
    config()->set('capell.roles.admin', 'site_manager');
    config()->set('capell.roles.editor', 'content_writer');
    foreach (['site_manager', 'content_writer', 'admin', 'editor'] as $name) {
        Role::findOrCreate($name, 'web');
    }

    GrantHtmlCacheDefaultRolePermissionsAction::run('web');

    $clear = HtmlCachePermission::ClearCachedModelUrls->value;
    $fresh = fn (string $name): Role => Role::query()->with('permissions')->where('name', $name)->where('guard_name', 'web')->sole();

    expect($fresh('site_manager')->hasPermissionTo($clear))->toBeTrue()
        ->and($fresh('content_writer')->hasPermissionTo($clear))->toBeTrue()
        ->and($fresh('admin')->hasPermissionTo($clear))->toBeFalse()
        ->and($fresh('editor')->hasPermissionTo($clear))->toBeFalse();
});

it('is harmless to run the upgrade migration twice', function (): void {
    $admin = Role::findOrCreate('admin', 'web');
    $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_11_12_000001_grant_html_cache_permissions_to_default_roles.php';

    $migration->up();
    $migration->up();

    $role = Role::query()->with('permissions')->whereKey($admin->getKey())->sole();

    expect($role->permissions->pluck('name')->sort()->values()->all())->toBe([
        HtmlCachePermission::ClearCachedModelUrls->value,
        HtmlCachePermission::ViewCachedModelUrls->value,
    ])->and(DB::table(Config::string('permission.table_names.role_has_permissions'))->where('role_id', $admin->getKey())->count())->toBe(2);
});

it('clears every stored row of a page through the job, including old addresses and other ports', function (): void {
    [$site, $pageUrl, $page] = htmlCacheAuthorizationSitePage('every-row.test');
    $current = htmlCacheAuthorizationCachedUrl($pageUrl);
    // The page's address before its slug changed, still stored under the page.
    $oldSlug = htmlCacheAuthorizationCachedUrl($pageUrl, 'https://every-row.test/old-slug', '/old-slug');
    // The same page captured on a non-standard port.
    $otherPort = htmlCacheAuthorizationCachedUrl($pageUrl, 'https://every-row.test:8443/cached');
    $clearer = htmlCacheAuthorizationSiteUser($site, [HtmlCachePermission::ClearCachedModelUrls]);

    $job = unserialize(serialize(new ClearAuthorisedPageCacheJob($clearer, $page, false)));
    throw_unless($job instanceof ClearAuthorisedPageCacheJob, RuntimeException::class);
    $job->handle();

    expect($current->fresh())->toBeNull()
        ->and($oldSlug->fresh())->toBeNull()
        ->and($otherPort->fresh())->toBeNull();
});

it('clears the stored rows of a page the user may clear and keeps one on a site they cannot', function (): void {
    [$site, $pageUrl, $page] = htmlCacheAuthorizationSitePage('row-guard.test');
    [, $foreignPageUrl] = htmlCacheAuthorizationSitePage('row-guard-foreign.test');
    $own = htmlCacheAuthorizationCachedUrl($pageUrl);
    // A row stored under this page but owned by another site, e.g. after a domain moved.
    $foreign = htmlCacheAuthorizationCachedUrl($foreignPageUrl);
    $foreign->forceFill(['cacheable_type' => $page->getMorphClass(), 'cacheable_id' => $page->getKey()])->save();
    $clearer = htmlCacheAuthorizationSiteUser($site, [HtmlCachePermission::ClearCachedModelUrls]);

    expect(HtmlCacheAuthorization::canClearPage($clearer, $page))->toBeTrue()
        ->and(HtmlCacheAuthorization::canClearCachedUrl($clearer, $foreign))->toBeFalse();

    $job = unserialize(serialize(new ClearAuthorisedPageCacheJob($clearer, $page, false)));
    throw_unless($job instanceof ClearAuthorisedPageCacheJob, RuntimeException::class);
    $job->handle();

    expect($own->fresh())->toBeNull()
        ->and($foreign->fresh())->not->toBeNull();
});

it('does not let a stored row whose domain was deleted stop the rest of the page being cleared', function (): void {
    [$site, $pageUrl, $page] = htmlCacheAuthorizationSitePage('stale-domain.test');
    $own = htmlCacheAuthorizationCachedUrl($pageUrl);
    $retiredDomain = SiteDomain::query()->create([
        'site_id' => $site->id,
        'language_id' => $pageUrl->language_id,
        'scheme' => 'https',
        'domain' => 'retired-stale-domain.test',
        'path' => '/',
    ]);
    $stale = htmlCacheAuthorizationCachedUrl($pageUrl, 'https://retired-stale-domain.test/cached');
    $stale->forceFill(['site_domain_id' => $retiredDomain->getKey()])->save();
    $retiredDomain->delete();
    $clearer = htmlCacheAuthorizationSiteUser($site, [HtmlCachePermission::ClearCachedModelUrls]);

    expect(HtmlCacheAuthorization::canClearCachedUrl($clearer, $stale->fresh() ?? $stale))->toBeFalse();

    $job = unserialize(serialize(new ClearAuthorisedPageCacheJob($clearer, $page, false)));
    throw_unless($job instanceof ClearAuthorisedPageCacheJob, RuntimeException::class);
    $job->handle();

    expect($own->fresh())->toBeNull()
        ->and($stale->fresh())->not->toBeNull();
});

it('syncs the HTML cache permissions when the first user authenticates, not while booting', function (): void {
    // Bug caught: an admin could be left without the HTML cache permission rows if the sync
    // that moved off boot never ran, or a schema probe at boot would need a live database.
    Cache::forget('capell-html-cache.permissions-synced');
    Permission::query()->where('name', HtmlCachePermission::cases()[0]->value)->delete();

    event(new Authenticated('web', User::factory()->create()));

    expect(Permission::query()->where('name', HtmlCachePermission::cases()[0]->value)->exists())->toBeTrue();
});
