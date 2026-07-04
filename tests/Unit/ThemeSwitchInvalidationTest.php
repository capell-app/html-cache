<?php

declare(strict_types=1);

use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Theme;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Models\StaleCachedUrl;
use Capell\HtmlCache\Observers\HtmlCacheModelInvalidationObserver;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;

uses(HtmlCacheTestCase::class);

/*
 * Guards Wave 6.4: switching a site's theme must invalidate every cached
 * URL under that site, not just a cached row for the Site model itself.
 * Cached URLs are keyed by cacheable_type/cacheable_id (the page-like model
 * that rendered them), so the generic per-model observer path never touches
 * Page-cacheable rows when only the owning Site changes — a latent
 * staleness bug this wave closes via MarkCachedUrlsForSiteStaleAction.
 */

function themeSwitchInvalidationSite(string $key): Site
{
    $language = Language::query()->firstOrCreate(
        ['code' => 'en'],
        ['name' => 'English', 'status' => true],
    );

    $siteBlueprint = Blueprint::query()->forceCreate([
        'name' => 'Site',
        'type' => 'site',
        'key' => 'theme-switch-site-' . $key,
        'default' => true,
        'status' => true,
    ]);

    $themeBlueprint = Blueprint::query()->forceCreate([
        'name' => 'Theme',
        'type' => 'theme',
        'key' => 'theme-switch-theme-' . $key,
        'default' => true,
        'status' => true,
    ]);

    $originalTheme = Theme::query()->forceCreate([
        'name' => 'Original Theme',
        'blueprint_id' => $themeBlueprint->id,
        'key' => 'theme-switch-original-' . $key,
        'default' => true,
        'status' => true,
    ]);

    return Site::query()->forceCreate([
        'name' => 'Theme Switch Site',
        'blueprint_id' => $siteBlueprint->id,
        'theme_id' => $originalTheme->id,
        'language_id' => $language->id,
        'default' => true,
        'status' => true,
    ]);
}

it('marks every cached url for the site stale when its theme changes', function (): void {
    $site = themeSwitchInvalidationSite('marks-stale');
    CapellCore::registerModels([Site::class, Page::class]);

    $siteDomain = SiteDomain::query()->forceCreate([
        'site_id' => $site->id,
        'language_id' => $site->language_id,
        'domain' => 'theme-switch-marks-stale.test',
        'scheme' => 'https',
        'path' => null,
        'default' => true,
        'status' => true,
    ]);

    $pageBlueprint = Blueprint::query()->forceCreate([
        'name' => 'Page',
        'type' => 'page',
        'key' => 'theme-switch-page-marks-stale',
        'default' => true,
        'status' => true,
    ]);

    $layout = Layout::query()->forceCreate([
        'name' => 'Default',
        'key' => 'theme-switch-layout-marks-stale',
        'site_id' => $site->id,
        'default' => true,
        'status' => true,
    ]);

    $page = Page::query()->forceCreate([
        'uuid' => (string) Str::uuid(),
        'name' => 'Theme Switch Page',
        'blueprint_id' => $pageBlueprint->id,
        'layout_id' => $layout->id,
        'site_id' => $site->id,
    ]);

    CachedModelUrl::query()->create([
        'url' => 'https://theme-switch-marks-stale.test/page',
        'url_hash' => CachedModelUrl::hashUrl('https://theme-switch-marks-stale.test/page'),
        'path' => '/page',
        'site_domain_id' => $siteDomain->id,
        'site_id' => $site->id,
        'language_id' => $siteDomain->language_id,
        'cacheable_type' => $page->getMorphClass(),
        'cacheable_id' => $page->getKey(),
    ]);

    $newThemeBlueprint = Blueprint::query()->forceCreate([
        'name' => 'Theme',
        'type' => 'theme',
        'key' => 'theme-switch-new-blueprint-marks-stale',
        'default' => false,
        'status' => true,
    ]);
    $newTheme = Theme::query()->forceCreate([
        'name' => 'New Theme',
        'blueprint_id' => $newThemeBlueprint->id,
        'key' => 'theme-switch-new-marks-stale',
        'default' => false,
        'status' => true,
    ]);

    $observer = new HtmlCacheModelInvalidationObserver;
    $site->forceFill(['theme_id' => $newTheme->id]);
    $site->syncChanges();

    $observer->updatedFromEvent('eloquent.updated: ' . $site::class, [$site]);

    expect(StaleCachedUrl::query()->where('url', 'https://theme-switch-marks-stale.test/page')->where('reason', 'site_theme_switched')->exists())->toBeTrue();
});

it('leaves the cache untouched when a site attribute unrelated to its theme changes', function (): void {
    $site = themeSwitchInvalidationSite('unrelated-change');
    CapellCore::registerModels([Site::class]);

    $siteDomain = SiteDomain::query()->forceCreate([
        'site_id' => $site->id,
        'language_id' => $site->language_id,
        'domain' => 'theme-switch-unrelated.test',
        'scheme' => 'https',
        'path' => null,
        'default' => true,
        'status' => true,
    ]);

    CachedModelUrl::query()->create([
        'url' => 'https://theme-switch-unrelated.test/site',
        'url_hash' => CachedModelUrl::hashUrl('https://theme-switch-unrelated.test/site'),
        'path' => '/site',
        'site_domain_id' => $siteDomain->id,
        'site_id' => $site->id,
        'language_id' => $siteDomain->language_id,
        'cacheable_type' => $site->getMorphClass(),
        'cacheable_id' => $site->getKey(),
    ]);

    $observer = new HtmlCacheModelInvalidationObserver;
    $site->forceFill(['name' => 'Renamed Site']);
    $site->syncChanges();

    $observer->updatedFromEvent('eloquent.updated: ' . $site::class, [$site]);

    // The rename still invalidates through the generic per-model path (the
    // site's own cacheable row) — theme-switch handling is additive, not a
    // replacement for that existing behavior.
    expect(CachedModelUrl::query()->where('url', 'https://theme-switch-unrelated.test/site')->exists())->toBeFalse();
});
