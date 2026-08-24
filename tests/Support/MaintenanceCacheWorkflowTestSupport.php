<?php

declare(strict_types=1);

use Capell\Core\Contracts\Themes\ThemePreviewRendererInterface;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Theme;
use Capell\HtmlCache\Actions\EnsureHtmlCachePermissionsAction;
use Capell\HtmlCache\Enums\HtmlCachePermission;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Creates one site with a single default domain, ready to be targeted by
 * maintenance cache workflow tests.
 */
function maintenanceCacheSiteDomain(string $domain = 'maintenance.test', bool $enabled = true): SiteDomain
{
    $siteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => $domain,
        'path' => null,
    ]);

    if (! $enabled) {
        $siteDomain->site()->update(['status' => false]);
        $siteDomain->site->refresh();
    }

    return $siteDomain;
}

/**
 * A user who can manage maintenance cache (a global, team_id = null
 * permission grant) but is only assigned to the given sites, mirroring how
 * `HtmlCacheAdminTest.php` scopes non-super-admin actors: site access comes
 * from role team-assignment, not from the permission grant itself.
 *
 * This package's Testbench environment runs Spatie's default published
 * config, which ships with `'teams' => false`. With teams disabled,
 * `HasRoles::assignRole()` never writes a `team_id` at all — no matter what
 * `PermissionRegistrar::setPermissionsTeamId()` is set to beforehand — so
 * `HasSitePermissions::assignRoleForSite()` is a no-op here. Insert the
 * team-scoped pivot row directly instead, exactly as
 * `HtmlCacheAdminTest.php`'s "hides cache map clear actions..." test already
 * does for the same reason.
 *
 * @param  list<Site>  $sites
 */
function maintenanceCacheSiteManager(array $sites): User
{
    EnsureHtmlCachePermissionsAction::run();

    resolve(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $user = User::factory()->create();
    $user->givePermissionTo(HtmlCachePermission::ManageMaintenance->value);

    $role = Role::findOrCreate('maintenance-site-manager', 'web');

    foreach ($sites as $site) {
        DB::table('model_has_roles')->insert([
            'role_id' => $role->getKey(),
            'model_type' => $user->getMorphClass(),
            'model_id' => $user->getKey(),
            'team_id' => $site->getKey(),
        ]);
    }

    resolve(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/**
 * `GenerateMaintenancePagesJob` dispatches with `->afterCommit()`, and
 * `PurgeEdgeCacheAction` defers through an explicit `DB::afterCommit()`
 * callback. Under RefreshDatabase both run synchronously here whenever no
 * outer transaction is open at dispatch time — observed to hold for a
 * single-site prepare, but not reliably for every call shape (for example a
 * query joining several eager-loaded relations before the dispatch). Rather
 * than depend on that timing, flush the pending transaction's queued
 * callbacks explicitly and deterministically. `GenerateMaintenancePagesJob`
 * guards on `status !== STATUS_PENDING`, so calling this after a callback
 * already ran synchronously is a safe no-op.
 */
function flushPendingAfterCommitCallbacks(): void
{
    /** @var DatabaseTransactionsManager $manager */
    $manager = app('db.transactions');
    $manager->getPendingTransactions()->last()?->executeCallbacks();
}

/**
 * Binds a fake theme renderer so `GenerateMaintenancePageCacheAction` can run
 * end to end (rendering, storing, and recording the maintenance page in the
 * manifest) without a real theme.
 */
function fakeMaintenanceThemeRenderer(): void
{
    app()->instance(ThemePreviewRendererInterface::class, new class implements ThemePreviewRendererInterface
    {
        public function render(
            Theme $theme,
            Site $site,
            Page $page,
            ?Language $language = null,
            ?SiteDomain $siteDomain = null,
        ): Response {
            return new Response('<h1>Maintenance</h1>');
        }
    });
}
