<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\Core\Models\SiteDomain;
use Capell\HtmlCache\Actions\BuildCachedModelUrlDiagnosticsAction;
use Capell\HtmlCache\Actions\BuildHtmlCachePublicOutputSafetyDiagnosticsAction;
use Capell\HtmlCache\Actions\EnsureHtmlCachePermissionsAction;
use Capell\HtmlCache\Enums\HtmlCachePermission;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

require_once dirname(__DIR__) . '/Support/CachedModelUrlsTestSupport.php';

uses(HtmlCacheTestCase::class);

it('reports unsafe cached public html through package diagnostics', function (): void {
    Storage::fake('page_cache');
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    test()->actingAs($user);

    $siteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => null,
    ]);
    $page = Page::factory()
        ->recycle($siteDomain->site)
        ->withTranslations()
        ->create();
    $url = 'https://example.test/about';
    $cachePath = resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $siteDomain);

    Storage::disk('page_cache')->put($cachePath, '<div data-capell-editor="1"></div>');
    CachedModelUrl::query()->create([
        'url' => $url,
        'url_hash' => CachedModelUrl::hashUrl($url),
        'path' => '/about',
        'site_id' => $siteDomain->site_id,
        'site_domain_id' => $siteDomain->getKey(),
        'language_id' => $siteDomain->language_id,
        'cacheable_type' => $page->getMorphClass(),
        'cacheable_id' => $page->getKey(),
        'cached_at' => now(),
        'last_seen_at' => now(),
    ]);

    $checks = BuildHtmlCachePublicOutputSafetyDiagnosticsAction::run();

    expect($checks)->toHaveCount(1)
        ->and($checks[0]->status)->toBe('red')
        ->and($checks[0]->detail)->toContain('data-capell-editor');
});

it('scopes cached public html diagnostics to the selected site', function (): void {
    Storage::fake('page_cache');
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    test()->actingAs($user);

    $firstSiteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'first.test',
        'path' => null,
    ]);
    $secondSiteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'second.test',
        'path' => null,
    ]);
    $firstPage = Page::factory()
        ->recycle($firstSiteDomain->site)
        ->withTranslations()
        ->create();
    $secondPage = Page::factory()
        ->recycle($secondSiteDomain->site)
        ->withTranslations()
        ->create();

    Storage::disk('page_cache')->put(
        resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $firstSiteDomain),
        '<main>safe</main>',
    );
    Storage::disk('page_cache')->put(
        resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $secondSiteDomain),
        '<div data-capell-editor="1"></div>',
    );

    foreach ([[$firstSiteDomain, $firstPage], [$secondSiteDomain, $secondPage]] as [$siteDomain, $page]) {
        CachedModelUrl::query()->create([
            'url' => sprintf('https://%s/about', $siteDomain->domain),
            'url_hash' => CachedModelUrl::hashUrl(sprintf('https://%s/about', $siteDomain->domain)),
            'path' => '/about',
            'site_id' => $siteDomain->site_id,
            'site_domain_id' => $siteDomain->getKey(),
            'language_id' => $siteDomain->language_id,
            'cacheable_type' => $page->getMorphClass(),
            'cacheable_id' => $page->getKey(),
            'cached_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    $checks = BuildHtmlCachePublicOutputSafetyDiagnosticsAction::run((int) $firstSiteDomain->site_id);

    expect($checks)->toHaveCount(1)
        ->and($checks[0]->status)->toBe('green');
});

it('does not inspect path-based cached public html outside the selected site', function (): void {
    Storage::fake('page_cache');
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    test()->actingAs($user);

    $firstSiteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => '/uk',
    ]);
    $secondSiteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => '/fr',
    ]);

    Storage::disk('page_cache')->put(
        resolve(HtmlCachePathResolver::class)->pathForUrl('/', $firstSiteDomain),
        '<main>safe</main>',
    );
    Storage::disk('page_cache')->put(
        resolve(HtmlCachePathResolver::class)->pathForUrl('/', $secondSiteDomain),
        '<div data-capell-editor="1"></div>',
    );

    $checks = BuildHtmlCachePublicOutputSafetyDiagnosticsAction::run((int) $firstSiteDomain->site_id);

    expect(collect($checks)->pluck('status')->all())->not->toContain('red')
        ->and(collect($checks)->pluck('detail')->implode(' '))->not->toContain('data-capell-editor');
});

it('installs html cache permissions', function (): void {
    EnsureHtmlCachePermissionsAction::run();

    expect(Permission::query()
        ->whereIn('name', HtmlCachePermission::names())
        ->count())->toBe(count(HtmlCachePermission::cases()));
});

it('reports unsafe unindexed cached public html files', function (): void {
    Storage::fake('page_cache');
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    test()->actingAs($user);

    $siteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => null,
    ]);
    $orphanCachePath = resolve(HtmlCachePathResolver::class)->pathForUrl('/orphan', $siteDomain);

    Storage::disk('page_cache')->put($orphanCachePath, '<div data-capell-editor="1"></div>');

    $checks = BuildHtmlCachePublicOutputSafetyDiagnosticsAction::run((int) $siteDomain->site_id);

    expect(collect($checks)->pluck('status')->all())->toContain('amber', 'red')
        ->and(collect($checks)->pluck('detail')->implode(' '))->toContain('without cache index rows')
        ->and(collect($checks)->pluck('detail')->implode(' '))->toContain('data-capell-editor');
});

it('reports cached model url diagnostics for the selected site only', function (): void {
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    test()->actingAs($user);

    $firstSiteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'first.test',
        'path' => null,
    ]);
    $secondSiteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'second.test',
        'path' => null,
    ]);
    $firstPage = Page::factory()
        ->recycle($firstSiteDomain->site)
        ->withTranslations()
        ->create();
    $secondPage = Page::factory()
        ->recycle($secondSiteDomain->site)
        ->withTranslations()
        ->create();

    CachedModelUrl::query()->create([
        'url' => 'https://first.test/about',
        'url_hash' => CachedModelUrl::hashUrl('https://first.test/about'),
        'path' => '/about',
        'site_id' => $firstSiteDomain->site_id,
        'site_domain_id' => $firstSiteDomain->getKey(),
        'language_id' => $firstSiteDomain->language_id,
        'cacheable_type' => $firstPage->getMorphClass(),
        'cacheable_id' => $firstPage->getKey(),
        'cached_at' => now(),
        'last_seen_at' => now(),
    ]);
    CachedModelUrl::query()->create([
        'url' => 'https://second.test/about',
        'url_hash' => CachedModelUrl::hashUrl('https://second.test/about'),
        'path' => '/about',
        'site_id' => $secondSiteDomain->site_id,
        'site_domain_id' => $secondSiteDomain->getKey(),
        'language_id' => $secondSiteDomain->language_id,
        'cacheable_type' => $secondPage->getMorphClass(),
        'cacheable_id' => $secondPage->getKey(),
        'cached_at' => now(),
        'last_seen_at' => now(),
    ]);

    $checks = BuildCachedModelUrlDiagnosticsAction::run((int) $firstSiteDomain->site_id);

    expect($checks)->toHaveCount(1)
        ->and($checks[0]->status)->toBe('green')
        ->and($checks[0]->detail)->toContain('1 of 1');
});

it('reports when no cached model urls are tracked', function (): void {
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    test()->actingAs($user);

    $checks = BuildCachedModelUrlDiagnosticsAction::run();

    expect($checks)->toHaveCount(1)
        ->and($checks[0]->status)->toBe('amber')
        ->and($checks[0]->detail)->toBe(__('capell-html-cache::admin.no_cached_model_urls_tracked'));
});
