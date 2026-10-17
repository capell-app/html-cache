<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\Core\Models\SiteDomain;
use Capell\HtmlCache\Actions\ClearCachedUrlsForSurrogateKeysAction;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Capell\HtmlCache\Support\Cache\PageCache;
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

it('clears indexed site URLs without treating wildcard domains as concrete cache roots', function (?string $scheme, ?string $domain): void {
    Storage::fake('page_cache');
    [$siteDomain, , $url, $cachePath] = cachedPageForSurrogateKeyTest('/about');
    $wildcardDomain = EloquentModel::withoutEvents(fn (): SiteDomain => SiteDomain::factory()
        ->site($siteDomain->site)
        ->language($siteDomain->language)
        ->create(['scheme' => $scheme, 'domain' => $domain, 'path' => '/docs']));

    expect(ClearCachedUrlsForSurrogateKeysAction::run(['site-' . $siteDomain->site_id]))->toBe(1)
        ->and(Storage::disk('page_cache')->exists($cachePath))->toBeFalse()
        ->and(CachedModelUrl::query()->where('url', $url)->exists())->toBeFalse()
        ->and($wildcardDomain->fresh())->not->toBeNull();
})->with([
    'path-only domain' => ['https', null],
    'non-concrete wildcard hostname' => ['https', '*.example.test'],
    'scheme-agnostic domain' => [null, 'example.test'],
    'host and scheme agnostic domain' => [null, null],
]);

it('clears unindexed standard pages and sidecars while preserving neighbouring sites', function (): void {
    Storage::fake('page_cache');
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'shared-standard.test', 'port' => null, 'path' => null]);
    SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'shared-standard.test', 'port' => null, 'path' => '/neighbour']);
    $disk = Storage::disk('page_cache');
    foreach (['pc__index__pc.html', 'nested/page.html', 'nested/page.404.html', 'nested/page~f1234567890abcdef.html'] as $file) {
        $disk->put('https.shared-standard.test/' . $file, 'old theme');
        $disk->put('https.shared-standard.test/' . $file . PageCache::FRAGMENT_METADATA_EXTENSION, '{}');
    }
    $disk->put('https.shared-standard.test/neighbour/page.html', 'neighbour');
    $disk->put('https.shared-standard.test/neighbour.html', 'neighbour homepage');
    $disk->put('https.unrelated.test/page.html', 'unrelated');
    $disk->put('~port-8080/https.shared-standard.test/neighbour/page.html', 'port neighbour');

    expect(CachedModelUrl::query()->count())->toBe(0)
        ->and(ClearCachedUrlsForSurrogateKeysAction::run(['site-' . $domain->site_id]))->toBe(4)
        ->and($disk->allFiles('https.shared-standard.test'))->toBe(['https.shared-standard.test/neighbour.html', 'https.shared-standard.test/neighbour/page.html'])
        ->and($disk->get('https.unrelated.test/page.html'))->toBe('unrelated')
        ->and($disk->get('~port-8080/https.shared-standard.test/neighbour/page.html'))->toBe('port neighbour');
});

it('clears only the selected standard mount without a dependency index', function (): void {
    Storage::fake('page_cache');
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'mounted-standard.test', 'port' => null, 'path' => '/selected']);
    SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'mounted-standard.test', 'port' => null, 'path' => null]);
    $disk = Storage::disk('page_cache');
    $disk->put('https.mounted-standard.test/selected/page.html', 'selected');
    $disk->put('https.mounted-standard.test/selected-other/page.html', 'sibling');
    $disk->put('https.mounted-standard.test/page.html', 'root site');

    expect(ClearCachedUrlsForSurrogateKeysAction::run(['site-' . $domain->site_id]))->toBe(1)
        ->and($disk->exists('https.mounted-standard.test/selected/page.html'))->toBeFalse()
        ->and($disk->get('https.mounted-standard.test/selected-other/page.html'))->toBe('sibling')
        ->and($disk->get('https.mounted-standard.test/page.html'))->toBe('root site');
});

it('clears an unindexed mounted homepage with its variants and sidecars', function (string $mount): void {
    Storage::fake('page_cache');
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'mounted-home.test', 'port' => null, 'path' => $mount]);
    SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'mounted-home.test', 'port' => null, 'path' => null]);
    $disk = Storage::disk('page_cache');
    $homepage = resolve(HtmlCachePathResolver::class)->pathForRequestUrl($domain->full_url, $domain);
    $stem = substr($homepage, 0, -strlen('.html'));
    foreach (['.html', '.404.html', '~1234567890abcdef.html', '~f1234567890abcdef.404.html', '.json', '.xml'] as $suffix) {
        $disk->put($stem . $suffix, 'old theme');
        $disk->put($stem . $suffix . PageCache::FRAGMENT_METADATA_EXTENSION, '{}');
    }
    $disk->put('https.mounted-home.test/pc__index__pc.html', 'root homepage');
    $disk->put($stem . '-other.html', 'sibling');

    expect(CachedModelUrl::query()->count())->toBe(0)
        ->and(ClearCachedUrlsForSurrogateKeysAction::run(['site-' . $domain->site_id]))->toBe(4)
        ->and($disk->allFiles('https.mounted-home.test'))->toEqualCanonicalizing([
            'https.mounted-home.test/pc__index__pc.html', $stem . '-other.html',
        ])
        ->and($disk->get('https.mounted-home.test/pc__index__pc.html'))->toBe('root homepage')
        ->and($disk->get($stem . '-other.html'))->toBe('sibling');
})->with(['/selected', '/selected.404', '/nested/selected', '/selected/index']);

it('preserves unindexed neighbouring mounts whose names resemble cache suffixes', function (string $mount): void {
    Storage::fake('page_cache');
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'suffix-neighbour.test', 'port' => null, 'path' => null]);
    $neighbour = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'suffix-neighbour.test', 'port' => null, 'path' => $mount]);
    $disk = Storage::disk('page_cache');
    $homepage = resolve(HtmlCachePathResolver::class)->pathForRequestUrl($neighbour->full_url, $neighbour);
    $stem = substr($homepage, 0, -strlen('.html'));
    $preserved = [];
    foreach (['.html', '.404.html', '~1234567890abcdef.html', '~f1234567890abcdef.404.html'] as $suffix) {
        $preserved[$stem . $suffix] = 'neighbour theme ' . $suffix;
        $preserved[$stem . $suffix . PageCache::FRAGMENT_METADATA_EXTENSION] = '{}';
    }
    foreach ($preserved as $file => $contents) {
        $disk->put($file, $contents);
    }
    $disk->put('https.suffix-neighbour.test/pc__index__pc.html', 'selected theme');

    expect(CachedModelUrl::query()->count())->toBe(0)
        ->and(ClearCachedUrlsForSurrogateKeysAction::run(['site-' . $domain->site_id]))->toBe(1)
        ->and($disk->allFiles('https.suffix-neighbour.test'))->toEqualCanonicalizing(array_keys($preserved));
    foreach ($preserved as $file => $contents) {
        expect($disk->get($file))->toBe($contents);
    }
})->with(['/neighbour.404', '/neighbour~1234567890abcdef', '/neighbour~f1234567890abcdef']);
