<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\Core\Models\SiteDomain;
use Capell\HtmlCache\Actions\ClearCachedUrlsForSurrogateKeysAction;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\Facades\Storage;

uses(HtmlCacheTestCase::class);

/**
 * @return array{0: SiteDomain, 1: Page, 2: string, 3: string}
 */
function cachedPageForSurrogateKeyTest(string $path, string $domain = 'example.test'): array
{
    return EloquentModel::withoutEvents(function () use ($path, $domain): array {
        $siteDomain = SiteDomain::factory()->create([
            'scheme' => 'https',
            'domain' => $domain,
            'path' => null,
        ]);
        $page = Page::factory()
            ->recycle($siteDomain->site)
            ->withTranslations()
            ->create();
        $url = 'https://' . $domain . $path;
        $cachePath = resolve(HtmlCachePathResolver::class)->pathForUrl($path, $siteDomain);

        Storage::disk('page_cache')->put($cachePath, 'old cached page');
        CachedModelUrl::query()->create([
            'url' => $url,
            'url_hash' => CachedModelUrl::hashUrl($url),
            'path' => $path,
            'site_id' => $siteDomain->site_id,
            'site_domain_id' => $siteDomain->getKey(),
            'language_id' => $siteDomain->language_id,
            'cacheable_type' => $page->getMorphClass(),
            'cacheable_id' => $page->getKey(),
            'cached_at' => now(),
            'last_seen_at' => now(),
        ]);

        return [$siteDomain, $page, $url, $cachePath];
    });
}

it('clears cached html for a page surrogate key', function (): void {
    Storage::fake('page_cache');

    [, $page, $url, $cachePath] = cachedPageForSurrogateKeyTest('/about');

    expect(ClearCachedUrlsForSurrogateKeysAction::run(['page-' . $page->id]))->toBe(1)
        ->and(Storage::disk('page_cache')->exists($cachePath))->toBeFalse()
        ->and(CachedModelUrl::query()->where('url', $url)->exists())->toBeFalse();
});

it('still clears cached html for a site surrogate key', function (): void {
    Storage::fake('page_cache');

    [$siteDomain, , $url, $cachePath] = cachedPageForSurrogateKeyTest('/about');

    expect(ClearCachedUrlsForSurrogateKeysAction::run(['site-' . $siteDomain->site_id]))->toBe(1)
        ->and(Storage::disk('page_cache')->exists($cachePath))->toBeFalse()
        ->and(CachedModelUrl::query()->where('url', $url)->exists())->toBeFalse();
});

it('clears both page and site scoped surrogate keys in a single call', function (): void {
    Storage::fake('page_cache');

    [, $pageOne, , $cachePathOne] = cachedPageForSurrogateKeyTest('/page-one', 'example-one.test');
    [$siteDomainTwo, , , $cachePathTwo] = cachedPageForSurrogateKeyTest('/page-two', 'example-two.test');

    $cleared = ClearCachedUrlsForSurrogateKeysAction::run([
        'page-' . $pageOne->id,
        'site-' . $siteDomainTwo->site_id,
    ]);

    expect($cleared)->toBe(2)
        ->and(Storage::disk('page_cache')->exists($cachePathOne))->toBeFalse()
        ->and(Storage::disk('page_cache')->exists($cachePathTwo))->toBeFalse();
});

it('ignores surrogate keys that match neither the page nor the site format', function (): void {
    Storage::fake('page_cache');

    expect(ClearCachedUrlsForSurrogateKeysAction::run(['lang-en', 'not-a-real-key', '']))->toBe(0);
});

it('does not resolve a page surrogate key against an unrelated site id', function (): void {
    Storage::fake('page_cache');

    [$siteDomain, $page, , $cachePath] = cachedPageForSurrogateKeyTest('/about');
    $unrelatedSiteId = $siteDomain->site_id + 999;

    expect(ClearCachedUrlsForSurrogateKeysAction::run(['site-' . $unrelatedSiteId]))->toBe(0)
        ->and(Storage::disk('page_cache')->exists($cachePath))->toBeTrue()
        ->and(CachedModelUrl::query()->where('cacheable_id', $page->getKey())->exists())->toBeTrue();
});
