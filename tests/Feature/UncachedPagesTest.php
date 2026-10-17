<?php

declare(strict_types=1);

use Capell\Admin\Enums\AdminSurfaceContributionType;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\SiteDomain;
use Capell\Frontend\Actions\Performance\RecordExtensionRenderContributionAction;
use Capell\HtmlCache\Actions\BuildUncachedPagesAction;
use Capell\HtmlCache\Actions\EnsureHtmlCachePermissionsAction;
use Capell\HtmlCache\Data\UncachedPageData;
use Capell\HtmlCache\Data\UncachedPagesData;
use Capell\HtmlCache\Enums\HtmlCachePermission;
use Capell\HtmlCache\Filament\Pages\UncachedPagesPage;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Models\StaleCachedUrl;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Capell\HtmlCache\Support\Cache\PageCache;
use Capell\HtmlCache\Support\Telemetry\HtmlCacheRequestObservationStore;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Capell\Tests\Fixtures\Models\User;

use const Dom\HTML_NO_DEFAULT_NS;

use Dom\HTMLDocument;
use Dom\Node;
use Dom\XPath;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(HtmlCacheTestCase::class);

it('shows the same measured metrics in the admin inventory and URL diagnostics without recording an inspection', function (): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'observed.test', 'path' => null]);
    $pageUrl = uncachedPageUrl($domain, '/measured');
    $pageUrl->setRelation('siteDomain', $domain);

    $url = $pageUrl->fullUrl();
    $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_10_12_000001_create_html_cache_request_observations_table.php';
    if (! Schema::hasTable(HtmlCacheRequestObservationStore::TABLE)) {
        $migration->up();
    }

    $store = resolve(HtmlCacheRequestObservationStore::class);
    $hash = HtmlCacheRequestObservationStore::pageUrlHash($pageUrl);
    $store->record($hash, (int) $domain->site_id, (int) $domain->language_id, 120.25, 10);
    $store->record($hash, (int) $domain->site_id, (int) $domain->language_id, null, 30);

    $before = DB::table(HtmlCacheRequestObservationStore::TABLE)->get()->toArray();
    $row = uncachedPageRow(BuildUncachedPagesAction::run(), $url);
    Artisan::call('capell:html-cache:diagnose', ['url' => $url, '--json' => true]);
    $diagnosed = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($diagnosed) || ! is_array($diagnosed['requestMetrics'] ?? null)) {
        throw new RuntimeException('The diagnostic must expose the measured request metrics.');
    }

    $component = Livewire::test(UncachedPagesPage::class)->assertSuccessful();
    $xpath = uncachedPagesXPath($component->html());
    $rendered = $xpath->query('//tbody/tr[th[contains(., "https://observed.test/measured")]]')->item(0);

    expect($row->lastRenderMilliseconds)->toBe(120.25)
        ->and($row->hitRate)->toBe(75.0)
        ->and($diagnosed['requestMetrics']['lastRenderMilliseconds'])->toEqual($row->lastRenderMilliseconds)
        ->and($diagnosed['requestMetrics']['hitRate'])->toEqual($row->hitRate)
        ->and($xpath->evaluate('normalize-space(td[3])', $rendered))->toBe('120.25')
        ->and($xpath->evaluate('normalize-space(td[5]/span)', $rendered))->toBe('75.00%')
        ->and($xpath->evaluate('string(td[5]//time/@datetime)', $rendered))->toBe($diagnosed['requestMetrics']['observedSince'])
        ->and(DB::table(HtmlCacheRequestObservationStore::TABLE)->get()->toArray())->toEqual($before);
});

beforeEach(function (): void {
    config(['capell-html-cache.enabled' => true, 'capell-html-cache.write_enabled' => true]);
    Storage::fake('page_cache');
    EnsureHtmlCachePermissionsAction::run();
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    test()->actingAs($user);
});

function uncachedPageUrl(SiteDomain $domain, string $path): PageUrl
{
    $page = Page::factory()->site($domain->site)->withTranslations($domain->language)->create();

    return PageUrl::factory()->page($page)->site($domain->site)->language($domain->language)
        ->create(['url' => $path]);
}

function uncachedPageRow(UncachedPagesData $overview, int|string $identifier): UncachedPageData
{
    foreach ($overview->pages as $row) {
        if ($row->pageUrlId === $identifier || $row->url === $identifier) {
            return $row;
        }
    }

    throw new RuntimeException('Expected uncached page row was not found: ' . $identifier);
}

function uncachedPagesXPath(string $html): XPath
{
    return new XPath(HTMLDocument::createFromString(
        '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>',
        HTML_NO_DEFAULT_NS,
    ));
}

it('matches the non-rendering diagnose command reasons for the same public URLs', function (bool $writesEnabled): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'parity.test', 'path' => null]);
    uncachedPageUrl($domain, '/bypassed');
    uncachedPageUrl($domain, '/old')->update(['type' => UrlTypeEnum::Redirect, 'target_url' => '/new']);
    uncachedPageUrl($domain, '/eligible');
    config(['capell-html-cache.bypass.paths' => ['/bypassed'], 'capell-html-cache.write_enabled' => $writesEnabled]);
    $overview = BuildUncachedPagesAction::run();

    foreach (['/bypassed', '/old', '/eligible'] as $path) {
        $url = 'https://parity.test' . $path;
        $exit = Artisan::call('capell:html-cache:diagnose', ['url' => $url, '--json' => true]);
        $diagnosis = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($diagnosis) || ! is_array($diagnosis['reasons'] ?? null)) {
            throw new RuntimeException('The diagnose command did not return a reason list.');
        }

        expect($exit)->toBe(0)
            ->and(uncachedPageRow($overview, $url)->reasons)->toBe($diagnosis['reasons']);
        if (! $writesEnabled) {
            expect($diagnosis['reasons'])->toContain('cache_write_disabled');
        }
    }
})->with([true, false]);

it('shows anonymous diagnose reasons when an authenticated admin views uncached pages', function (): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'anonymous-parity.test', 'path' => null]);
    uncachedPageUrl($domain, '/bypassed');
    uncachedPageUrl($domain, '/eligible');
    config(['capell-html-cache.bypass.paths' => ['/bypassed']]);
    $admin = Auth::user();
    if (! $admin instanceof User) {
        throw new RuntimeException('The inventory must be viewed by an authenticated admin.');
    }

    Auth::forgetGuards();
    $diagnoses = [];
    foreach (['/bypassed' => ['configured_bypass_rule'], '/eligible' => []] as $path => $reasons) {
        $url = 'https://anonymous-parity.test' . $path;
        expect(Artisan::call('capell:html-cache:diagnose', ['url' => $url, '--json' => true]))->toBe(0);
        $diagnosis = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($diagnosis) || ! is_array($diagnosis['reasons'] ?? null)) {
            throw new RuntimeException('The diagnose command must return anonymous eligibility reasons.');
        }

        expect($diagnosis['reasons'])->toBe($reasons)
            ->and($diagnosis['eligible'])->toBe($reasons === []);
        $diagnoses[$url] = $reasons;
    }

    $this->actingAs($admin);
    $previousRequest = request();
    $overview = BuildUncachedPagesAction::run();
    foreach ($diagnoses as $url => $reasons) {
        expect(uncachedPageRow($overview, $url)->reasons)->toBe($reasons);
    }

    expect(request())->toBe($previousRequest);

    $component = Livewire::test(UncachedPagesPage::class)->assertSuccessful()
        ->assertDontSee('authenticated_or_session_request');
    $xpath = uncachedPagesXPath($component->html());
    foreach ($diagnoses as $url => $reasons) {
        $rows = $xpath->query('//tbody/tr[th[contains(., "' . $url . '")]]');
        if ($rows->length !== 1 || ! ($row = $rows->item(0)) instanceof Node) {
            throw new RuntimeException('The admin inventory must include the diagnosed URL: ' . $url);
        }

        $renderedReasons = [];
        foreach ($xpath->query('td[2]/ul[1]/li/code', $row) as $reason) {
            $renderedReasons[] = $reason->textContent;
        }

        expect($renderedReasons)->toBe($reasons)
            ->and($xpath->evaluate('count(td[2]/ul[2]/li/code[text()="cache_entry_missing"])', $row))->toBe(1.0)
            ->and($xpath->evaluate('count(td[2]/ul[2]/li/code[text()="no_recorded_attempt"])', $row))->toBe(1.0);
    }

    // CLI inspections invoked from an authenticated process must also remain anonymous.
    $previousRequest = request();
    foreach ($diagnoses as $url => $reasons) {
        expect(Artisan::call('capell:html-cache:diagnose', ['url' => $url, '--json' => true]))->toBe(0);
        $diagnosis = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($diagnosis) || ! is_array($diagnosis['reasons'] ?? null)) {
            throw new RuntimeException('The diagnose command must return anonymous eligibility reasons.');
        }

        expect($diagnosis['reasons'])->toBe($reasons);
    }

    expect(Auth::user())->toBe($admin)
        ->and(request())->toBe($previousRequest);
});

it('renders a seeded uncached page with its blocking reason and excludes a cached page', function (): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'rendered.test', 'path' => null]);
    uncachedPageUrl($domain, '/bypassed');
    uncachedPageUrl($domain, '/cached');
    config(['capell-html-cache.bypass.paths' => ['/bypassed']]);
    $disk = Storage::disk('page_cache');
    $path = resolve(HtmlCachePathResolver::class)->pathForRequestUrl('https://rendered.test/cached');
    $disk->put($path, '<main>Cached</main>');

    $component = Livewire::test(UncachedPagesPage::class)->assertSuccessful()
        ->assertSee('https://rendered.test/bypassed')->assertSee('configured_bypass_rule')
        ->assertDontSee('https://rendered.test/cached');
    $xpath = uncachedPagesXPath($component->html());
    $rows = $xpath->query('//tbody/tr[th[contains(., "https://rendered.test/bypassed")]]');
    if ($rows->length !== 1 || ! ($row = $rows->item(0)) instanceof Node) {
        throw new RuntimeException('The seeded uncached page should have exactly one table row.');
    }

    foreach ([3, 4, 5] as $column) {
        expect($xpath->evaluate('normalize-space(td[' . $column . '])', $row))->toBe('');
    }

    expect($disk->allFiles())->toBe([$path])
        ->and($disk->get($path))->toBe('<main>Cached</main>')
        ->and(StaleCachedUrl::query()->count())->toBe(0);
});

it('orders available render durations slowest first with unrecorded durations last', function (): void {
    $rows = [
        new UncachedPageData(1, 'https://sort.test/a-unknown', 'Site', [], null, null, null, 0),
        new UncachedPageData(2, 'https://sort.test/fast', 'Site', [], null, null, null, 0, 12.5),
        new UncachedPageData(3, 'https://sort.test/z-slow', 'Site', [], null, null, null, 0, 240.25, 75.5),
        new UncachedPageData(4, 'https://sort.test/b-unknown', 'Site', [], null, null, null, 0),
        new UncachedPageData(5, 'https://sort.test/a-slow', 'Site', [], null, null, null, 0, 240.25),
        new UncachedPageData(6, 'https://sort.test/zero', 'Site', [], null, null, null, 0, 0.0, 0.0),
    ];
    $overview = new UncachedPagesData($rows);
    expect(collect($overview->pages)->pluck('pageUrlId')->all())->toBe([5, 3, 2, 6, 1, 4]);
    $html = view('capell-html-cache::filament.pages.partials.uncached-pages-table', ['overview' => $overview])->render();
    expect($html)->toContain('240.25', '12.50', '75.50%', '0.00%');
    $xpath = uncachedPagesXPath($html);
    $urls = $xpath->query('//tbody/tr/th');
    $rendered = [];
    foreach ($urls as $url) {
        $rendered[] = trim($url->textContent ?? '');
    }

    expect($rendered)->toBe(collect($overview->pages)->pluck('url')->all());
});

it('lists missing files even with an index and excludes usable files even without an index', function (): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'uncached.test', 'path' => null]);
    uncachedPageUrl($domain, '/missing');
    uncachedPageUrl($domain, '/file-only');
    $url = 'https://uncached.test/missing';
    foreach ([1, 2] as $dependencyId) {
        CachedModelUrl::query()->create([
            'url' => $url, 'url_hash' => CachedModelUrl::hashUrl($url), 'path' => '/missing',
            'site_id' => $domain->site_id, 'site_domain_id' => $domain->id, 'language_id' => $domain->language_id,
            'cacheable_type' => Page::class, 'cacheable_id' => $dependencyId,
            'cached_at' => '2026-01-02 12:00:00', 'hit_count' => 7,
        ]);
    }

    $path = resolve(HtmlCachePathResolver::class)->pathForRequestUrl('https://uncached.test/file-only');
    Storage::disk('page_cache')->put($path, '<main>Public</main>');

    $overview = BuildUncachedPagesAction::run();
    $rows = $overview->pages;

    expect(collect($rows)->pluck('url')->all())->toContain($url)->not->toContain('https://uncached.test/file-only');
    $row = uncachedPageRow($overview, $url);
    expect($row->cacheIssues)->toContain('cache_entry_missing', 'no_recorded_attempt')
        ->and($row->lastCachedAt)->toBe('2026-01-02T12:00:00+00:00')
        ->and($row->cachedHits)->toBe(7)
        ->and($row->lastRenderMilliseconds)->toBeNull()
        ->and($row->hitRate)->toBeNull();
});

it('combines current eligibility and recorded refresh failure without using the admin request', function (): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'reasons.test', 'path' => null]);
    uncachedPageUrl($domain, '/private');
    config(['capell-html-cache.bypass.paths' => ['/private'], 'capell-html-cache.write_enabled' => false]);
    $url = 'https://reasons.test/private';
    StaleCachedUrl::query()->create([
        'url' => $url, 'url_hash' => CachedModelUrl::hashUrl($url), 'path' => '/private',
        'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl($url), $domain->site_id, $domain->id, '/private'),
        'site_id' => $domain->site_id, 'site_domain_id' => $domain->id, 'language_id' => $domain->language_id,
        'cache_path' => 'old/private.html', 'status' => StaleCachedUrl::STATUS_FAILED,
        'last_error' => 'Not cacheable: response_private',
    ]);
    $previous = request();
    RecordExtensionRenderContributionAction::run(
        packageName: 'capell-app/html-cache',
        surface: 'admin',
        contributionType: 'admin-page',
        contributionClass: UncachedPagesPage::class,
        elapsedMilliseconds: 5,
        frontendRenderBudgetMs: 20,
        cacheTags: [],
        cacheable: false,
        sensitiveOutput: true,
        variesBy: [],
    );

    $row = uncachedPageRow(BuildUncachedPagesAction::run(), $url);

    expect($row->reasons)->toContain('configured_bypass_rule', 'cache_write_disabled')
        ->not->toContain('authenticated_or_session_request', 'package_cache_blocking', 'package_sensitive_output')
        ->and($row->cacheIssues)->toContain('cache_path_mismatch')
        ->and($row->lastError)->toBe('Not cacheable: response_private')
        ->and($row->refreshStatus)->toBe(StaleCachedUrl::STATUS_FAILED)
        ->and(request())->toBe($previous);
});

it('inspects expired and empty files without deleting them or resolving the cache writer', function (): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'expired.test', 'path' => null]);
    uncachedPageUrl($domain, '/expired');
    uncachedPageUrl($domain, '/empty');
    config(['capell-html-cache.filesystem_ttl_seconds' => 60]);
    $disk = Storage::disk('page_cache');
    $path = resolve(HtmlCachePathResolver::class)->pathForRequestUrl('https://expired.test/expired');
    $disk->put($path, '<main>Expired</main>');
    touch($disk->path($path), now()->subMinutes(2)->getTimestamp());
    $emptyPath = resolve(HtmlCachePathResolver::class)->pathForRequestUrl('https://expired.test/empty');
    $disk->put($emptyPath, '');
    app()->bind(PageCache::class, fn (): PageCache => throw new RuntimeException('Read-only diagnostics must not resolve PageCache.'));

    $overview = BuildUncachedPagesAction::run();

    expect(uncachedPageRow($overview, 'https://expired.test/expired')->cacheIssues)->toContain('cache_entry_expired')
        ->and(uncachedPageRow($overview, 'https://expired.test/empty')->cacheIssues)->toContain('cache_entry_empty')
        ->and($disk->get($path))->toBe('<main>Expired</main>')
        ->and($disk->get($emptyPath))->toBe('');
});

it('expands enabled locale domains and ports and ignores inactive URLs and sites', function (): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'mounted.test', 'path' => '/en', 'port' => 8443]);
    $other = SiteDomain::factory()->create([
        'site_id' => $domain->site_id, 'language_id' => $domain->language_id,
        'scheme' => 'https', 'domain' => 'alias.test', 'path' => null,
    ]);
    uncachedPageUrl($domain, '/hello');
    $language = Language::factory()->create();
    $locale = SiteDomain::factory()->create([
        'site_id' => $domain->site_id, 'language_id' => $language->id,
        'scheme' => 'https', 'domain' => 'mounted.test', 'path' => '/fr', 'port' => 8443,
    ]);
    uncachedPageUrl($locale, '/bonjour');
    uncachedPageUrl($domain, '/disabled')->update(['status' => false]);
    $inactive = SiteDomain::factory()->create(['domain' => 'inactive.test']);
    uncachedPageUrl($inactive, '/hidden');
    $inactive->site()->update(['status' => false]);
    $urls = collect(BuildUncachedPagesAction::run()->pages)->map(fn (UncachedPageData $row): string => $row->url);

    expect($urls)->toContain('https://mounted.test:8443/en/hello', 'https://alias.test/hello', 'https://mounted.test:8443/fr/bonjour')
        ->not->toContain('https://mounted.test:8443/en/disabled', 'https://mounted.test:8443/en/bonjour')
        ->and($urls->filter(fn (string $url): bool => str_contains($url, 'inactive.test')))->toBeEmpty();

    $other->update(['status' => false]);
    expect(collect(BuildUncachedPagesAction::run()->pages)->pluck('url'))->not->toContain('https://alias.test/hello');
});

it('reports missing domains and redirects explicitly', function (): void {
    $domain = SiteDomain::factory()->create();
    $missing = uncachedPageUrl($domain, '/no-domain');
    $domain->delete();
    $redirectDomain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'redirect.test', 'path' => null]);
    uncachedPageUrl($redirectDomain, '/old')->update(['type' => UrlTypeEnum::Redirect, 'target_url' => '/new']);

    $overview = BuildUncachedPagesAction::run();

    expect(uncachedPageRow($overview, $missing->id)->reasons)->toContain('missing_site_domain')
        ->and(uncachedPageRow($overview, 'https://redirect.test/old')->reasons)->toContain('redirect_url');
});

it('excludes CMS URLs whose blueprints are disabled or not publicly accessible', function (): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'public.test', 'path' => null]);
    $visible = uncachedPageUrl($domain, '/visible');
    $private = uncachedPageUrl($domain, '/private-blueprint');
    $disabled = uncachedPageUrl($domain, '/disabled-blueprint');
    Page::query()->whereKey($private->pageable_id)->firstOrFail()->update(['blueprint_id' => Blueprint::factory()->page()->create(['meta' => ['accessible' => false]])->id]);
    Page::query()->whereKey($disabled->pageable_id)->firstOrFail()->update(['blueprint_id' => Blueprint::factory()->page()->create(['status' => false])->id]);

    $ids = collect(BuildUncachedPagesAction::run()->pages)->pluck('pageUrlId');

    expect($ids)->toContain($visible->id)->not->toContain($private->id, $disabled->id);
});

it('uses the Core public URL normalisation for wildcard CMS paths', function (): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'wildcard.test', 'path' => null]);
    $pageUrl = uncachedPageUrl($domain, '/section/*');
    $row = uncachedPageRow(BuildUncachedPagesAction::run(), $pageUrl->id);

    expect($row->url)->toBe('https://wildcard.test/section');
});

it('denies guests and users without cache view permission and scopes permitted users to assigned sites', function (): void {
    auth()->logout();
    expect(UncachedPagesPage::canAccess())->toBeFalse();
    Livewire::test(UncachedPagesPage::class)->assertForbidden();
    $user = User::factory()->create();
    test()->actingAs($user);
    expect(UncachedPagesPage::canAccess())->toBeFalse();
    Livewire::test(UncachedPagesPage::class)->assertForbidden();
    EnsureHtmlCachePermissionsAction::run();
    $user->givePermissionTo(HtmlCachePermission::ViewCachedModelUrls->value);
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'allowed.test', 'path' => null]);
    $hidden = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'hidden.test', 'path' => null]);
    uncachedPageUrl($domain, '/visible');
    uncachedPageUrl($hidden, '/secret');
    $url = 'https://allowed.test/visible';
    StaleCachedUrl::query()->create([
        'url' => $url, 'url_hash' => CachedModelUrl::hashUrl($url), 'path' => '/visible',
        'stale_key' => StaleCachedUrl::staleKey(CachedModelUrl::hashUrl($url), $domain->site_id, $domain->id, '/visible'),
        'site_id' => $domain->site_id, 'site_domain_id' => $domain->id, 'language_id' => $domain->language_id,
        'status' => StaleCachedUrl::STATUS_NOT_APPLICABLE, 'last_error' => '<script>alert("private")</script>',
    ]);
    $role = Role::findOrCreate('cache-viewer', 'web');
    DB::table('model_has_roles')->insert([
        'role_id' => $role->id, 'model_type' => $user->getMorphClass(), 'model_id' => $user->id,
        'team_id' => $domain->site_id,
    ]);
    $user->unsetRelation('roles');

    expect(UncachedPagesPage::canAccess())->toBeTrue();
    Livewire::test(UncachedPagesPage::class)->assertSuccessful()
        ->assertSee('https://allowed.test/visible')->assertDontSee('https://hidden.test/secret')
        ->assertSee(__('capell-html-cache::dashboard.status_not_applicable'))
        ->assertDontSee('<script>alert("private")</script>', escape: false)
        ->assertSee(__('capell-html-cache::admin.uncached_metrics_gap'));
});

it('registers the translated developer page and declares it in the package manifest', function (): void {
    expect(collect(CapellAdmin::getAdminSurfaceContributions(AdminSurfaceContributionType::Page))->pluck('class')->all())
        ->toContain(UncachedPagesPage::class);
    $contents = file_get_contents(__DIR__ . '/../../capell.json');
    if ($contents === false) {
        throw new RuntimeException('Package manifest could not be read.');
    }

    $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($manifest) || ! is_array($manifest['contributes'] ?? null)) {
        throw new RuntimeException('Package manifest has no contribution list.');
    }

    expect(collect($manifest['contributes'])->where('type', 'admin-page')->pluck('pageClass')->all())
        ->toContain(UncachedPagesPage::class)
        ->and(UncachedPagesPage::getNavigationLabel())->toBe('Uncached public pages');
});
