<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Translation;
use Capell\HtmlCache\Actions\ClearCachedUrlAction;
use Capell\HtmlCache\Actions\RecordCachedModelUrlsAction;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Illuminate\Support\Facades\Storage;

uses(HtmlCacheTestCase::class);

it('records rendered models against a cached url and removes stale model links', function (): void {
    $siteDomain = SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => null,
    ]);
    $page = Page::factory()
        ->recycle($siteDomain->site)
        ->withTranslations()
        ->create();
    $translation = $page->translations()->where('language_id', $siteDomain->language_id)->first();

    expect($translation)->toBeInstanceOf(Translation::class);
    $url = 'https://example.test/about';

    RecordCachedModelUrlsAction::run($url, [
        $page->getMorphClass() => [$page->getKey()],
        $translation->getMorphClass() => [$translation->getKey()],
    ]);

    expect(CachedModelUrl::query()->where('url', $url)->count())->toBe(2)
        ->and(CachedModelUrl::query()->where('url', $url)->first())
        ->site_id->toBe($siteDomain->site_id)
        ->site_domain_id->toBe($siteDomain->getKey())
        ->language_id->toBe($siteDomain->language_id)
        ->path->toBe('/about');

    RecordCachedModelUrlsAction::run($url, [
        $page->getMorphClass() => [$page->getKey()],
    ]);

    expect(CachedModelUrl::query()->where('url', $url)->count())->toBe(1)
        ->and(CachedModelUrl::query()->where('url', $url)->first())
        ->cacheable_type->toBe($page->getMorphClass())
        ->cacheable_id->toBe($page->getKey());
});

it('clears cached files and table rows for a url', function (): void {
    Storage::fake('page_cache');

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
    $errorCachePath = resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $siteDomain, error: true);

    Storage::disk('page_cache')->put($cachePath, 'cached page');
    Storage::disk('page_cache')->put($errorCachePath, 'cached error page');
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

    expect(ClearCachedUrlAction::run($url))->toBeTrue()
        ->and(Storage::disk('page_cache')->exists($cachePath))->toBeFalse()
        ->and(Storage::disk('page_cache')->exists($errorCachePath))->toBeFalse()
        ->and(CachedModelUrl::query()->where('url', $url)->exists())->toBeFalse();
});
