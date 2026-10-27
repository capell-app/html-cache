<?php

declare(strict_types=1);

use Capell\Core\Contracts\Themes\ThemePreviewRendererInterface;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Theme;
use Capell\Frontend\Support\Maintenance\MaintenanceManifestStore;
use Capell\HtmlCache\Actions\BuildMaintenanceCacheOverviewAction;
use Capell\HtmlCache\Actions\DisableGlobalMaintenanceAction;
use Capell\HtmlCache\Actions\DisableSiteMaintenanceOverrideAction;
use Capell\HtmlCache\Actions\EnableGlobalMaintenanceAction;
use Capell\HtmlCache\Actions\EnableSiteMaintenanceOverrideAction;
use Capell\HtmlCache\Actions\PrepareMaintenanceCacheAction;
use Capell\HtmlCache\Actions\PrepareSiteMaintenanceCacheAction;
use Capell\HtmlCache\Contracts\CachePurger;
use Capell\HtmlCache\Data\EdgeCachePurgeData;
use Capell\HtmlCache\Data\MaintenanceCacheOverviewData;
use Capell\HtmlCache\Enums\MaintenanceCacheAttentionReason;
use Capell\HtmlCache\Enums\MaintenanceCacheStatus;
use Capell\HtmlCache\Enums\MaintenanceGlobalAction;
use Capell\HtmlCache\Enums\MaintenanceSiteAction;
use Capell\HtmlCache\Enums\MaintenanceSiteStatus;
use Capell\HtmlCache\Filament\Pages\MaintenanceCachePage;
use Capell\HtmlCache\Jobs\GenerateMaintenancePagesJob;
use Capell\HtmlCache\Models\HtmlCacheGenerationRun;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;

require_once __DIR__ . '/../Support/MaintenanceCacheWorkflowTestSupport.php';

uses(HtmlCacheTestCase::class);

// The maintenance manifest is a plain JSON file under storage_path(), not a
// database row: RefreshDatabase's per-test transaction rollback does not
// touch it, so a previous test's global_active/site domains would otherwise
// leak into the next test in this file. Reset it, and Artisan's own
// maintenance-mode flag, before every test. Also drain any DB::afterCommit
// callback a previous test left pending and never explicitly flushed
// (dispatchAfterCommit callbacks queue on a process-wide transactions
// manager, not something RefreshDatabase's rollback clears), so it cannot
// fire against this test's freshly bound recording purger.
beforeEach(function (): void {
    flushPendingAfterCommitCallbacks();

    resolve(MaintenanceManifestStore::class)->write([
        'global_active' => false,
        'fallback' => null,
        'sites' => [],
    ]);

    if (app()->maintenanceMode()->active()) {
        Artisan::call('up');
    }
});

/**
 * Records every purge call so the tests can assert the exact edge-cache
 * purge behaviour (tags vs. purgeAll) rather than merely "a purge happened".
 */
function bindRecordingCachePurger(): void
{
    app()->instance(CachePurger::class, new class implements CachePurger
    {
        /** @var list<EdgeCachePurgeData> */
        public array $calls = [];

        public function purge(EdgeCachePurgeData $purge): bool
        {
            $this->calls[] = $purge;

            return true;
        }
    });
}

/** @return list<EdgeCachePurgeData> */
function recordedPurges(): array
{
    /** @var object{calls: list<EdgeCachePurgeData>} $purger */
    $purger = resolve(CachePurger::class);

    return $purger->calls;
}

it('is off with no generated output and reports zero of N sites ready', function (): void {
    $globalAdmin = User::factory()->create();
    $globalAdmin->assignRole('super_admin');

    test()->actingAs($globalAdmin);

    $siteDomain = maintenanceCacheSiteDomain('off-state.test');

    $overview = BuildMaintenanceCacheOverviewAction::run(collect([$siteDomain->site]));

    expect($overview->status)->toBe(MaintenanceCacheStatus::Off)
        ->and($overview->readySites)->toBe(0)
        ->and($overview->totalSites)->toBe(1)
        ->and($overview->primaryAction())->toBe(MaintenanceGlobalAction::Prepare)
        ->and($overview->sites[0]->status)->toBe(MaintenanceSiteStatus::Missing)
        ->and($overview->sites[0]->action())->toBe(MaintenanceSiteAction::Prepare);
});

it('flags attention when no accessible site is enabled', function (): void {
    $globalAdmin = User::factory()->create();
    $globalAdmin->assignRole('super_admin');

    test()->actingAs($globalAdmin);

    $overview = BuildMaintenanceCacheOverviewAction::run(collect());

    expect($overview->status)->toBe(MaintenanceCacheStatus::Attention)
        ->and($overview->attentionReasons)->toContain(MaintenanceCacheAttentionReason::NoAccessibleSites)
        ->and($overview->primaryAction())->toBeNull();
});

it('flags attention when no accessible site has a domain configured', function (): void {
    $globalAdmin = User::factory()->create();
    $globalAdmin->assignRole('super_admin');

    test()->actingAs($globalAdmin);

    $site = Site::factory()->create(['status' => true]);
    $site->load('siteDomains');

    $overview = BuildMaintenanceCacheOverviewAction::run(collect([$site]));

    expect($overview->status)->toBe(MaintenanceCacheStatus::Attention)
        ->and($overview->attentionReasons)->toContain(MaintenanceCacheAttentionReason::NoSiteDomainsConfigured)
        ->and($overview->primaryAction())->toBeNull();
});

it('flags attention with a retry action after a failed generation run', function (): void {
    $globalAdmin = User::factory()->create();
    $globalAdmin->assignRole('super_admin');

    test()->actingAs($globalAdmin);

    $siteDomain = maintenanceCacheSiteDomain('failed-run.test');

    HtmlCacheGenerationRun::query()->create([
        'status' => HtmlCacheGenerationRun::STATUS_FAILED,
        'total_sites' => 1,
        'site_ids' => [$siteDomain->site_id],
        'completed_sites' => 0,
        'failed_sites' => 1,
        'finished_at' => now(),
    ]);

    $overview = BuildMaintenanceCacheOverviewAction::run(collect([$siteDomain->site]));

    expect($overview->status)->toBe(MaintenanceCacheStatus::Attention)
        ->and($overview->attentionReasons)->toContain(MaintenanceCacheAttentionReason::GenerationFailed)
        ->and($overview->primaryAction())->toBe(MaintenanceGlobalAction::Prepare);
});

it('flags attention when the manifest and Artisan maintenance state disagree', function (): void {
    $globalAdmin = User::factory()->create();
    $globalAdmin->assignRole('super_admin');

    test()->actingAs($globalAdmin);

    $siteDomain = maintenanceCacheSiteDomain('drift.test');

    Artisan::call('down');

    try {
        $overview = BuildMaintenanceCacheOverviewAction::run(collect([$siteDomain->site]));

        expect($overview->status)->toBe(MaintenanceCacheStatus::Attention)
            ->and($overview->attentionReasons)->toContain(MaintenanceCacheAttentionReason::ArtisanStateDrift)
            ->and($overview->primaryAction())->toBe(MaintenanceGlobalAction::ExitMaintenance);
    } finally {
        Artisan::call('up');
    }
});

it('is preparing while a generation run targets an accessible site', function (): void {
    $globalAdmin = User::factory()->create();
    $globalAdmin->assignRole('super_admin');

    test()->actingAs($globalAdmin);

    $siteDomain = maintenanceCacheSiteDomain('preparing.test');

    HtmlCacheGenerationRun::query()->create([
        'status' => HtmlCacheGenerationRun::STATUS_RUNNING,
        'total_sites' => 1,
        'site_ids' => [$siteDomain->site_id],
        'enable_global' => false,
        'completed_sites' => 0,
        'failed_sites' => 0,
        'started_at' => now(),
    ]);

    $overview = BuildMaintenanceCacheOverviewAction::run(collect([$siteDomain->site]));

    expect($overview->status)->toBe(MaintenanceCacheStatus::Preparing)
        ->and($overview->primaryAction())->toBeNull()
        ->and($overview->sites[0]->status)->toBe(MaintenanceSiteStatus::Preparing)
        ->and($overview->sites[0]->action())->toBeNull();
});

it('is ready once every accessible enabled site has generated output', function (): void {
    $globalAdmin = User::factory()->create();
    $globalAdmin->assignRole('super_admin');

    test()->actingAs($globalAdmin);

    $siteDomain = maintenanceCacheSiteDomain('ready.test');
    resolve(MaintenanceManifestStore::class)->replaceSiteDomains((int) $siteDomain->site_id, [[
        'scheme' => 'https',
        'domain' => 'ready.test',
        'path' => '/',
        'site_id' => $siteDomain->site_id,
        'site_domain_id' => $siteDomain->id,
        'language_id' => $siteDomain->language_id,
        'file' => 'maintenance/https.ready.test/index.html',
        'generated_at' => now()->toIso8601String(),
    ]]);

    $overview = BuildMaintenanceCacheOverviewAction::run(collect([$siteDomain->site]));

    expect($overview->status)->toBe(MaintenanceCacheStatus::Ready)
        ->and($overview->readySites)->toBe(1)
        ->and($overview->primaryAction())->toBe(MaintenanceGlobalAction::ReviewAndEnable)
        ->and($overview->sites[0]->status)->toBe(MaintenanceSiteStatus::Generated)
        ->and($overview->sites[0]->action())->toBe(MaintenanceSiteAction::EnableOverride)
        ->and($overview->sites[0]->lastGeneratedAt)->not->toBeNull();
});

it('is active when the global manifest flag is set, and covers every site regardless of its own override', function (): void {
    $globalAdmin = User::factory()->create();
    $globalAdmin->assignRole('super_admin');

    test()->actingAs($globalAdmin);

    $siteDomain = maintenanceCacheSiteDomain('active.test');
    // A consistent Active state has both signals agree: the manifest flag
    // and Artisan's own maintenance mode. Setting only the manifest flag is
    // exactly the drift scenario covered separately above.
    resolve(MaintenanceManifestStore::class)->setGlobalActive(true);
    Artisan::call('down');

    try {
        $overview = BuildMaintenanceCacheOverviewAction::run(collect([$siteDomain->site]));

        expect($overview->status)->toBe(MaintenanceCacheStatus::Active)
            ->and($overview->primaryAction())->toBe(MaintenanceGlobalAction::ExitMaintenance)
            ->and($overview->sites[0]->status)->toBe(MaintenanceSiteStatus::CoveredByGlobal)
            ->and($overview->sites[0]->action())->toBeNull();
    } finally {
        Artisan::call('up');
        resolve(MaintenanceManifestStore::class)->setGlobalActive(false);
    }
});

// --- PrepareMaintenanceCacheAction (global "Prepare") -----------------------

it('denies preparing maintenance cache to an actor without the manage permission', function (): void {
    $viewer = User::factory()->create();

    expect(fn (): HtmlCacheGenerationRun => PrepareMaintenanceCacheAction::run($viewer))
        ->toThrow(AuthorizationException::class);
});

it('refuses to prepare when the actor has no accessible enabled sites', function (): void {
    $manager = maintenanceCacheSiteManager([]);
    test()->actingAs($manager);

    expect(fn (): HtmlCacheGenerationRun => PrepareMaintenanceCacheAction::run($manager))
        ->toThrow(RuntimeException::class, 'No accessible sites to prepare.');
});

it('prepares only the actor accessible enabled sites, leaving others untouched', function (): void {
    fakeMaintenanceThemeRenderer();

    $assignedSiteDomain = maintenanceCacheSiteDomain('assigned-prepare.test');
    $otherSiteDomain = maintenanceCacheSiteDomain('other-prepare.test');
    $manager = maintenanceCacheSiteManager([$assignedSiteDomain->site]);
    test()->actingAs($manager);

    $run = PrepareMaintenanceCacheAction::run($manager);
    flushPendingAfterCommitCallbacks();
    $run->refresh();

    expect($run->status)->toBe(HtmlCacheGenerationRun::STATUS_COMPLETED)
        ->and($run->site_ids)->toBe([(int) $assignedSiteDomain->site_id])
        ->and($run->enable_global)->toBeFalse();

    $manifest = resolve(MaintenanceManifestStore::class)->read();

    expect(data_get($manifest, 'sites.' . $assignedSiteDomain->site_id . '.domains'))->not->toBe([])
        ->and(data_get($manifest, 'sites.' . $otherSiteDomain->site_id . '.domains', []))->toBe([]);
});

it('refuses to queue a second global prepare while one is already in flight', function (): void {
    Queue::fake();

    $siteDomain = maintenanceCacheSiteDomain('queue-guard.test');
    $manager = maintenanceCacheSiteManager([$siteDomain->site]);
    test()->actingAs($manager);

    PrepareMaintenanceCacheAction::run($manager);

    expect(fn (): HtmlCacheGenerationRun => PrepareMaintenanceCacheAction::run($manager))
        ->toThrow(RuntimeException::class, 'A cache generation run is already active.');

    Queue::assertPushed(GenerateMaintenancePagesJob::class, 1);
});

// --- PrepareSiteMaintenanceCacheAction ---------------------------------------

it('denies preparing a site outside the actor assigned sites', function (): void {
    $assignedSiteDomain = maintenanceCacheSiteDomain('assigned-site-prepare.test');
    $unassignedSiteDomain = maintenanceCacheSiteDomain('unassigned-site-prepare.test');
    $manager = maintenanceCacheSiteManager([$assignedSiteDomain->site]);

    expect(fn (): HtmlCacheGenerationRun => PrepareSiteMaintenanceCacheAction::run($manager, (int) $unassignedSiteDomain->site_id))
        ->toThrow(AuthorizationException::class);
});

it('prepares a single site and records the generated timestamp', function (): void {
    fakeMaintenanceThemeRenderer();

    $siteDomain = maintenanceCacheSiteDomain('single-site-prepare.test');
    $manager = maintenanceCacheSiteManager([$siteDomain->site]);

    $run = PrepareSiteMaintenanceCacheAction::run($manager, (int) $siteDomain->site_id);
    flushPendingAfterCommitCallbacks();

    expect($run->site_ids)->toBe([(int) $siteDomain->site_id]);

    $overview = BuildMaintenanceCacheOverviewAction::run(collect([$siteDomain->site->fresh(['siteDomains', 'language'])]));

    expect($overview->sites[0]->lastGeneratedAt)->not->toBeNull()
        ->and($overview->sites[0]->domains[0]->generatedAt)->not->toBeNull();
});

// --- EnableSiteMaintenanceOverrideAction / DisableSiteMaintenanceOverrideAction ---

it('refuses to enable a site override before the site has been prepared', function (): void {
    $siteDomain = maintenanceCacheSiteDomain('unprepared-enable.test');
    $manager = maintenanceCacheSiteManager([$siteDomain->site]);

    expect(function () use ($manager, $siteDomain): void {
        EnableSiteMaintenanceOverrideAction::run($manager, (int) $siteDomain->site_id);
    })->toThrow(RuntimeException::class);
});

it('enables a site override with an exact scoped edge purge, and is idempotent', function (): void {
    fakeMaintenanceThemeRenderer();

    $siteDomain = maintenanceCacheSiteDomain('enable-override.test');
    $manager = maintenanceCacheSiteManager([$siteDomain->site]);
    $siteId = (int) $siteDomain->site_id;

    PrepareSiteMaintenanceCacheAction::run($manager, $siteId);
    flushPendingAfterCommitCallbacks();

    // Bind the recording purger only now: preparing the page itself can
    // trigger unrelated cache-invalidation side effects, and this test only
    // cares about the purge the enable action itself issues.
    bindRecordingCachePurger();
    EnableSiteMaintenanceOverrideAction::run($manager, $siteId);
    flushPendingAfterCommitCallbacks();

    expect(data_get(resolve(MaintenanceManifestStore::class)->read(), 'sites.' . $siteId . '.active'))->toBeTrue()
        ->and(recordedPurges())->toHaveCount(1)
        ->and(recordedPurges()[0]->tags)->toBe(['site-' . $siteId])
        ->and(recordedPurges()[0]->purgeAll)->toBeFalse();

    // Idempotent: already active, no second purge.
    EnableSiteMaintenanceOverrideAction::run($manager, $siteId);
    flushPendingAfterCommitCallbacks();

    expect(recordedPurges())->toHaveCount(1);
});

it('re-checks readiness before enabling a site override that was concurrently cleared', function (): void {
    fakeMaintenanceThemeRenderer();
    bindRecordingCachePurger();

    $siteDomain = maintenanceCacheSiteDomain('concurrent-clear.test');
    $manager = maintenanceCacheSiteManager([$siteDomain->site]);
    $siteId = (int) $siteDomain->site_id;

    PrepareSiteMaintenanceCacheAction::run($manager, $siteId);
    flushPendingAfterCommitCallbacks();
    EnableSiteMaintenanceOverrideAction::run($manager, $siteId);
    DisableSiteMaintenanceOverrideAction::run($manager, $siteId);

    // Another admin (or a manifest rebuild) clears this site's generated
    // output concurrently.
    resolve(MaintenanceManifestStore::class)->replaceSiteDomains($siteId, []);

    expect(function () use ($manager, $siteId): void {
        EnableSiteMaintenanceOverrideAction::run($manager, $siteId);
    })->toThrow(RuntimeException::class);
});

it('denies enabling or disabling a site override outside the actor assigned sites', function (): void {
    $assignedSiteDomain = maintenanceCacheSiteDomain('assigned-toggle.test');
    $unassignedSiteDomain = maintenanceCacheSiteDomain('unassigned-toggle.test');
    $manager = maintenanceCacheSiteManager([$assignedSiteDomain->site]);
    $unassignedSiteId = (int) $unassignedSiteDomain->site_id;

    expect(function () use ($manager, $unassignedSiteId): void {
        EnableSiteMaintenanceOverrideAction::run($manager, $unassignedSiteId);
    })->toThrow(AuthorizationException::class);

    expect(function () use ($manager, $unassignedSiteId): void {
        DisableSiteMaintenanceOverrideAction::run($manager, $unassignedSiteId);
    })->toThrow(AuthorizationException::class);
});

it('disabling an already inactive site override is a no-op with no purge', function (): void {
    $siteDomain = maintenanceCacheSiteDomain('already-off.test');
    $manager = maintenanceCacheSiteManager([$siteDomain->site]);

    // Bind only now: creating the site/domain above legitimately triggers
    // Capell's own model-invalidation observer (a full cache clear), which
    // is unrelated to whether disabling an already-inactive override purges
    // anything.
    flushPendingAfterCommitCallbacks();
    bindRecordingCachePurger();

    DisableSiteMaintenanceOverrideAction::run($manager, (int) $siteDomain->site_id);

    expect(recordedPurges())->toHaveCount(0);
});

// --- EnableGlobalMaintenanceAction / DisableGlobalMaintenanceAction ----------

it('denies enabling global maintenance to a site-scoped, non-global actor', function (): void {
    $siteDomain = maintenanceCacheSiteDomain('non-global.test');
    $manager = maintenanceCacheSiteManager([$siteDomain->site]);

    expect(fn (): HtmlCacheGenerationRun => EnableGlobalMaintenanceAction::run($manager))
        ->toThrow(AuthorizationException::class);
});

it('refuses to enable global maintenance until every accessible site is ready', function (): void {
    $globalAdmin = User::factory()->create();
    $globalAdmin->assignRole('super_admin');

    test()->actingAs($globalAdmin);
    maintenanceCacheSiteDomain('not-ready.test');

    expect(fn (): HtmlCacheGenerationRun => EnableGlobalMaintenanceAction::run($globalAdmin))
        ->toThrow(RuntimeException::class, 'All accessible sites must be prepared before enabling global maintenance.');
});

it('refuses to enable global maintenance a second time while it is already active', function (): void {
    $globalAdmin = User::factory()->create();
    $globalAdmin->assignRole('super_admin');

    test()->actingAs($globalAdmin);
    maintenanceCacheSiteDomain('already-active.test');
    resolve(MaintenanceManifestStore::class)->setGlobalActive(true);

    try {
        expect(fn (): HtmlCacheGenerationRun => EnableGlobalMaintenanceAction::run($globalAdmin))
            ->toThrow(RuntimeException::class, 'Global maintenance is already active.');
    } finally {
        resolve(MaintenanceManifestStore::class)->setGlobalActive(false);
    }
});

it('enables global maintenance end to end: generates, flips the manifest, downs Artisan, and queues the bypass cookie', function (): void {
    fakeMaintenanceThemeRenderer();
    bindRecordingCachePurger();

    $globalAdmin = User::factory()->create();
    $globalAdmin->assignRole('super_admin');

    test()->actingAs($globalAdmin);
    $siteDomain = maintenanceCacheSiteDomain('enable-global.test');

    PrepareMaintenanceCacheAction::run($globalAdmin);
    flushPendingAfterCommitCallbacks();

    try {
        $run = EnableGlobalMaintenanceAction::run($globalAdmin);
        flushPendingAfterCommitCallbacks();

        expect($run->enable_global)->toBeTrue()
            ->and(resolve(MaintenanceManifestStore::class)->read()['global_active'])->toBeTrue()
            ->and(app()->maintenanceMode()->active())->toBeTrue()
            ->and(Cookie::hasQueued('laravel_maintenance'))->toBeTrue();

        $overview = BuildMaintenanceCacheOverviewAction::run(collect([$siteDomain->site]));

        expect($overview->status)->toBe(MaintenanceCacheStatus::Active)
            ->and($overview->sites[0]->status)->toBe(MaintenanceSiteStatus::CoveredByGlobal);
    } finally {
        Artisan::call('up');
        resolve(MaintenanceManifestStore::class)->setGlobalActive(false);
    }
});

it('denies exiting maintenance to a site-scoped, non-global actor', function (): void {
    $siteDomain = maintenanceCacheSiteDomain('exit-denied.test');
    $manager = maintenanceCacheSiteManager([$siteDomain->site]);

    expect(function () use ($manager): void {
        DisableGlobalMaintenanceAction::run($manager);
    })->toThrow(AuthorizationException::class);
});

it('exiting maintenance when nothing is active is a no-op with no purge', function (): void {
    bindRecordingCachePurger();

    $globalAdmin = User::factory()->create();
    $globalAdmin->assignRole('super_admin');

    DisableGlobalMaintenanceAction::run($globalAdmin);

    expect(recordedPurges())->toHaveCount(0);
});

it('exits maintenance from the active state with a full purgeAll', function (): void {
    bindRecordingCachePurger();

    $globalAdmin = User::factory()->create();
    $globalAdmin->assignRole('super_admin');

    Artisan::call('down');
    resolve(MaintenanceManifestStore::class)->setGlobalActive(true);

    DisableGlobalMaintenanceAction::run($globalAdmin);
    flushPendingAfterCommitCallbacks();

    expect(app()->maintenanceMode()->active())->toBeFalse()
        ->and(resolve(MaintenanceManifestStore::class)->read()['global_active'])->toBeFalse()
        ->and(recordedPurges())->toHaveCount(1)
        ->and(recordedPurges()[0]->purgeAll)->toBeTrue();
});

it('exiting maintenance also resolves an Artisan/manifest state drift', function (): void {
    bindRecordingCachePurger();

    $globalAdmin = User::factory()->create();
    $globalAdmin->assignRole('super_admin');

    // Someone ran `php artisan down` directly, bypassing this page.
    Artisan::call('down');

    expect(resolve(MaintenanceManifestStore::class)->read()['global_active'])->toBeFalse();

    DisableGlobalMaintenanceAction::run($globalAdmin);

    expect(app()->maintenanceMode()->active())->toBeFalse();
});

// --- Filament page integration ------------------------------------------

it('shows exactly the applicable global header action for each state', function (): void {
    $globalAdmin = User::factory()->create();
    $globalAdmin->assignRole('super_admin');

    test()->actingAs($globalAdmin);

    maintenanceCacheSiteDomain('header-actions.test');

    // The page never registers the inapplicable actions at all (not merely
    // hides them), so `assertActionHidden` — which expects a real, resolvable
    // Action instance — does not apply here. Assert the single applicable
    // action directly and that the other two labels never render.
    $component = Livewire::test(MaintenanceCachePage::class)
        ->assertActionVisible('prepare')
        ->assertDontSee(MaintenanceGlobalAction::ReviewAndEnable->label())
        ->assertDontSee(MaintenanceGlobalAction::ExitMaintenance->label());

    $page = $component->instance();
    throw_unless($page instanceof MaintenanceCachePage, RuntimeException::class, 'Expected a maintenance page.');

    expect($page->getCachedHeaderActions())->toHaveCount(1);
});

it('scopes site rows and actions to the actor accessible sites', function (): void {
    fakeMaintenanceThemeRenderer();

    $assignedSiteDomain = maintenanceCacheSiteDomain('assigned-row.test');
    $unassignedSiteDomain = maintenanceCacheSiteDomain('unassigned-row.test');
    $manager = maintenanceCacheSiteManager([$assignedSiteDomain->site]);
    test()->actingAs($manager);

    $page = new MaintenanceCachePage;

    expect($page->sites()->pluck('id'))
        ->toContain($assignedSiteDomain->site_id)
        ->not->toContain($unassignedSiteDomain->site_id);

    $page->prepareSite((int) $assignedSiteDomain->site_id);
    flushPendingAfterCommitCallbacks();

    expect(data_get($page->manifest(), 'sites.' . $assignedSiteDomain->site_id . '.domains'))->not->toBe([]);

    $unassignedSiteId = (int) $unassignedSiteDomain->site_id;

    expect(function () use ($page, $unassignedSiteId): void {
        $page->prepareSite($unassignedSiteId);
    })->toThrow(AuthorizationException::class);

    expect(function () use ($page, $unassignedSiteId): void {
        $page->enableSiteOverride($unassignedSiteId);
    })->toThrow(AuthorizationException::class);

    expect(function () use ($page, $unassignedSiteId): void {
        $page->disableSiteOverride($unassignedSiteId);
    })->toThrow(AuthorizationException::class);
});

it('notifies success when a site override is enabled and disabled through the page', function (): void {
    fakeMaintenanceThemeRenderer();

    $siteDomain = maintenanceCacheSiteDomain('page-notify.test');
    $manager = maintenanceCacheSiteManager([$siteDomain->site]);
    test()->actingAs($manager);

    $siteId = (int) $siteDomain->site_id;

    $component = Livewire::test(MaintenanceCachePage::class)
        ->call('prepareSite', $siteId)
        ->assertNotified(__('capell-html-cache::admin.maintenance_cache_queued'));

    flushPendingAfterCommitCallbacks();

    $component
        ->call('enableSiteOverride', $siteId)
        ->assertNotified(__('capell-html-cache::admin.maintenance_site_updated'))
        ->call('disableSiteOverride', $siteId)
        ->assertNotified(__('capell-html-cache::admin.maintenance_site_updated'));
});

it('warns instead of failing when enabling a site override before it is prepared', function (): void {
    $siteDomain = maintenanceCacheSiteDomain('page-warn.test');
    $manager = maintenanceCacheSiteManager([$siteDomain->site]);
    test()->actingAs($manager);

    Livewire::test(MaintenanceCachePage::class)
        ->call('enableSiteOverride', (int) $siteDomain->site_id)
        ->assertNotified();
});

it('refreshes queued preparation without redispatch and removes polling at terminal state', function (): void {
    Queue::fake();
    fakeMaintenanceThemeRenderer();
    $domain = maintenanceCacheSiteDomain('live-progress.test');
    $manager = maintenanceCacheSiteManager([$domain->site]);
    test()->actingAs($manager);
    $page = Livewire::test(MaintenanceCachePage::class)->callAction('prepare')
        ->assertSee(MaintenanceCacheStatus::Preparing->label())
        ->assertSeeHtml('wire:poll.5s="refreshStatus"');
    $run = HtmlCacheGenerationRun::query()->sole();
    $page->call('refreshStatus')->assertSeeHtml('wire:poll.5s="refreshStatus"');
    $run->update(['status' => HtmlCacheGenerationRun::STATUS_RUNNING, 'completed_sites' => 1]);
    $page->call('refreshStatus')->assertSee(__('capell-html-cache::admin.maintenance_run_progress', ['completed' => 1, 'total' => 1]));
    $run->update(['status' => HtmlCacheGenerationRun::STATUS_PENDING, 'completed_sites' => 0]);
    new GenerateMaintenancePagesJob($run->id, [(int) $domain->site_id])->handle();
    $page->call('refreshStatus')->assertSee(MaintenanceCacheStatus::Ready->label())
        ->assertDontSeeHtml('wire:poll.5s="refreshStatus"');
    Queue::assertPushed(GenerateMaintenancePagesJob::class, 1);
});

it('keeps status actions and polling coherent when preparation finishes between reads', function (bool $failed): void {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    test()->actingAs($admin);
    $preparing = new MaintenanceCacheOverviewData(
        status: MaintenanceCacheStatus::Preparing,
        globalManifestActive: false,
        artisanMaintenanceActive: false,
        totalSites: 1,
        readySites: 0,
        sites: [],
        currentRunId: 'controlled-run',
    );
    $terminal = new MaintenanceCacheOverviewData(
        status: $failed ? MaintenanceCacheStatus::Attention : MaintenanceCacheStatus::Ready,
        globalManifestActive: false,
        artisanMaintenanceActive: false,
        totalSites: 1,
        readySites: $failed ? 0 : 1,
        sites: [],
        attentionReasons: $failed ? [MaintenanceCacheAttentionReason::GenerationFailed] : [],
    );

    app()->instance('LaravelActions:AsFake:' . BuildMaintenanceCacheOverviewAction::class, Mockery::mock(BuildMaintenanceCacheOverviewAction::make()));
    $projection = $preparing;
    $state = new class
    {
        public bool $interleave = false;

        public int $reads = 0;
    };
    BuildMaintenanceCacheOverviewAction::shouldRun()->andReturnUsing(
        function () use (&$projection, $state, $preparing, $terminal): MaintenanceCacheOverviewData {
            return $state->interleave ? (++$state->reads <= 2 ? $preparing : $terminal) : $projection;
        },
    );
    $page = Livewire::test(MaintenanceCachePage::class);

    // Boot and refresh calculate header actions. The worker then completes,
    // so another projection for the view reproduces the completion race.
    $state->interleave = true;
    $page->call('refreshStatus')
        ->assertSee(MaintenanceCacheStatus::Preparing->label())
        ->assertSeeHtml('wire:poll.5s="refreshStatus"');
    $instance = $page->instance();
    throw_unless($instance instanceof MaintenanceCachePage, RuntimeException::class);
    expect($instance->getCachedHeaderActions())->toBe([]);

    $state->interleave = false;
    $projection = $terminal;
    $page->call('refreshStatus')
        ->assertSee($terminal->status->label())
        ->assertActionVisible($failed ? 'prepare' : 'review-and-enable')
        ->assertDontSeeHtml('wire:poll.5s="refreshStatus"');
})->with(['ready' => false, 'failed' => true]);

it('projects only accessible preparation failures safely and clears them after a successful replacement', function (): void {
    $domain = maintenanceCacheSiteDomain('visible-failure.test');
    $other = maintenanceCacheSiteDomain('hidden-failure.test');
    test()->actingAs(maintenanceCacheSiteManager([$domain->site]));
    $run = HtmlCacheGenerationRun::query()->create([
        'status' => HtmlCacheGenerationRun::STATUS_FAILED, 'site_ids' => [(int) $domain->site_id, (int) $other->site_id],
        'total_sites' => 2, 'completed_sites' => 0, 'failed_sites' => 2, 'finished_at' => now(),
        'errors' => [(string) $domain->site_id => 'render token=private-secret /private/path', (string) $other->site_id => 'hidden secret', 'job' => 'password=job-secret'],
    ]);
    $page = Livewire::test(MaintenanceCachePage::class)
        ->assertSee(__('capell-html-cache::admin.preparation_site_failed', ['site' => $domain->site->name]))
        ->assertSee(__('capell-html-cache::admin.preparation_worker_failed'))
        ->assertSee(__('capell-html-cache::admin.preparation_template_recovery'))
        ->assertDontSee('private-secret')->assertDontSee('job-secret')->assertDontSee('hidden secret')
        ->assertDontSee('hidden-failure.test');
    HtmlCacheGenerationRun::query()->create([
        'status' => HtmlCacheGenerationRun::STATUS_COMPLETED, 'site_ids' => [(int) $domain->site_id],
        'total_sites' => 1, 'completed_sites' => 1, 'finished_at' => now()->addSecond(),
    ]);
    $page->call('refreshStatus')->assertDontSee(__('capell-html-cache::admin.preparation_worker_failed'));
    expect($run->refresh()->errors)->toHaveCount(3);
});

it('withholds aggregate progress and failures belonging only to inaccessible sites', function (): void {
    $domain = maintenanceCacheSiteDomain('accessible-progress.test');
    $other = maintenanceCacheSiteDomain('private-progress.test');
    test()->actingAs(maintenanceCacheSiteManager([$domain->site]));
    $run = HtmlCacheGenerationRun::query()->create([
        'status' => HtmlCacheGenerationRun::STATUS_RUNNING, 'site_ids' => [(int) $domain->site_id, (int) $other->site_id],
        'total_sites' => 2, 'completed_sites' => 0, 'failed_sites' => 1,
    ]);
    $overview = BuildMaintenanceCacheOverviewAction::run(collect([$domain->site]));
    expect($overview->showRunCounters)->toBeFalse()->and($overview->currentRunFailedSites)->toBe(0)
        ->and($overview->currentRunTotalSites)->toBe(0);
    $run->update(['status' => HtmlCacheGenerationRun::STATUS_FAILED, 'finished_at' => now(), 'errors' => [(string) $other->site_id => 'Private failure']]);
    $overview = BuildMaintenanceCacheOverviewAction::run(collect([$domain->site]));
    expect($overview->failures)->toBe([])->and($overview->attentionReasons)->not->toContain(MaintenanceCacheAttentionReason::GenerationFailed);
});

it('shows a real two-site worker failure and clears its safe display after a successful replacement', function (): void {
    Queue::fake();
    $good = maintenanceCacheSiteDomain('working-worker.test');
    $bad = maintenanceCacheSiteDomain('broken-worker.test');
    test()->actingAs(maintenanceCacheSiteManager([$good->site, $bad->site]));
    $renderer = Mockery::mock(ThemePreviewRendererInterface::class);
    $renderer->shouldReceive('render')->andReturnUsing(function (Theme $theme, Site $site, Page $page, ?Language $language = null, ?SiteDomain $domain = null): Response {
        if ($domain?->domain === 'broken-worker.test') {
            throw new RuntimeException('View failed token=worker-secret /private/path');
        }

        return new Response('<h1>Maintenance</h1>');
    });
    app()->instance(ThemePreviewRendererInterface::class, $renderer);
    $run = PrepareMaintenanceCacheAction::run(auth()->user());
    new GenerateMaintenancePagesJob($run->id, $run->site_ids ?? [])->handle();
    expect($run->refresh()->status)->toBe(HtmlCacheGenerationRun::STATUS_FAILED)
        ->and($run->completed_sites)->toBe(1)->and($run->failed_sites)->toBe(1);
    $page = Livewire::test(MaintenanceCachePage::class)
        ->assertSee(__('capell-html-cache::admin.preparation_site_failed', ['site' => $bad->site->name]))
        ->assertDontSee(__('capell-html-cache::admin.preparation_site_failed', ['site' => $good->site->name]))
        ->assertDontSee('worker-secret')->assertDontSee('/private/path');
    fakeMaintenanceThemeRenderer();
    $replacement = PrepareMaintenanceCacheAction::run(auth()->user());
    new GenerateMaintenancePagesJob($replacement->id, $replacement->site_ids ?? [])->handle();
    $page->call('refreshStatus')->assertSee(MaintenanceCacheStatus::Ready->label())
        ->assertDontSee(__('capell-html-cache::admin.preparation_failures'));
    expect($run->refresh()->errors)->not->toBeEmpty()
        ->and($replacement->refresh()->status)->toBe(HtmlCacheGenerationRun::STATUS_COMPLETED);
});

it('keeps an unresolved site failure visible when another site is successfully prepared later', function (): void {
    $first = maintenanceCacheSiteDomain('unresolved-site.test');
    $second = maintenanceCacheSiteDomain('successful-site.test');
    test()->actingAs(maintenanceCacheSiteManager([$first->site, $second->site]));
    HtmlCacheGenerationRun::query()->create([
        'status' => HtmlCacheGenerationRun::STATUS_FAILED, 'site_ids' => [(int) $first->site_id],
        'total_sites' => 1, 'failed_sites' => 1, 'finished_at' => now(),
        'errors' => [(string) $first->site_id => 'View unavailable'],
    ]);
    HtmlCacheGenerationRun::query()->create([
        'status' => HtmlCacheGenerationRun::STATUS_COMPLETED, 'site_ids' => [(int) $second->site_id],
        'total_sites' => 1, 'completed_sites' => 1, 'finished_at' => now()->addSecond(),
    ]);
    Livewire::test(MaintenanceCachePage::class)
        ->assertSee(__('capell-html-cache::admin.preparation_site_failed', ['site' => $first->site->name]))
        ->assertSee(MaintenanceCacheStatus::Attention->label());
});

it('retains a disabled site failure without blocking readiness after enabled sites succeed', function (): void {
    Queue::fake();
    $failed = maintenanceCacheSiteDomain('disabled-failure.test');
    $enabled = maintenanceCacheSiteDomain('enabled-success.test');
    test()->actingAs(maintenanceCacheSiteManager([$failed->site, $enabled->site]));
    $renderer = Mockery::mock(ThemePreviewRendererInterface::class);
    $renderer->shouldReceive('render')->andThrow(new RuntimeException('View unavailable'));
    app()->instance(ThemePreviewRendererInterface::class, $renderer);

    $run = PrepareSiteMaintenanceCacheAction::run(auth()->user(), (int) $failed->site_id);
    new GenerateMaintenancePagesJob($run->id, $run->site_ids ?? [])->handle();
    expect($run->refresh()->status)->toBe(HtmlCacheGenerationRun::STATUS_FAILED);
    $failed->site->update(['status' => false]);

    fakeMaintenanceThemeRenderer();
    $replacement = PrepareMaintenanceCacheAction::run(auth()->user());
    expect($replacement->site_ids)->toBe([(int) $enabled->site_id]);
    new GenerateMaintenancePagesJob($replacement->id, $replacement->site_ids ?? [])->handle();
    expect($replacement->refresh()->status)->toBe(HtmlCacheGenerationRun::STATUS_COMPLETED);

    $overview = BuildMaintenanceCacheOverviewAction::run(collect([
        $failed->site->refresh()->load('siteDomains'), $enabled->site->refresh()->load('siteDomains'),
    ]));
    expect($overview->status)->toBe(MaintenanceCacheStatus::Ready)
        ->and($overview->primaryAction())->toBe(MaintenanceGlobalAction::ReviewAndEnable)
        ->and($overview->totalSites)->toBe(1)
        ->and($overview->readySites)->toBe(1)
        ->and($overview->attentionReasons)->not->toContain(MaintenanceCacheAttentionReason::GenerationFailed)
        ->and($overview->failures)->toHaveCount(1)
        ->and($overview->failures[0]->summary)->toBe(__('capell-html-cache::admin.preparation_site_failed', ['site' => $failed->site->name]))
        ->and($run->refresh()->errors)->not->toBeEmpty();
});
