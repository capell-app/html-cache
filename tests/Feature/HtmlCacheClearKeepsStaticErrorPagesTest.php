<?php

declare(strict_types=1);

use Capell\Core\Models\SiteDomain;
use Capell\Frontend\Support\Error\ErrorPagePathResolver;
use Capell\Frontend\Support\Maintenance\MaintenancePagePathResolver;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Capell\HtmlCache\Support\Cache\HtmlCacheStore;
use Capell\HtmlCache\Support\Cache\PageCache;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(HtmlCacheTestCase::class);

beforeEach(function (): void {
    Storage::fake('page_cache');
    Queue::fake();
    config()->set('capell-html-cache.enabled', true);
    config()->set('capell-html-cache.write_enabled', true);
});

/**
 * @return array{page: string, error: string, maintenance: string}
 */
function seedStaticArtefactsAndPage(): array
{
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);

    $paths = [
        'page' => resolve(HtmlCachePathResolver::class)->pathForUrl('/pricing', $domain),
        'error' => resolve(ErrorPagePathResolver::class)->pathForDomainAndStatus($domain, '404'),
        'maintenance' => resolve(MaintenancePagePathResolver::class)->pathForDomain($domain),
    ];

    foreach ($paths as $kind => $path) {
        Storage::disk('page_cache')->put($path, '<main>' . $kind . '</main>');
    }

    return $paths;
}

it('keeps the static error and maintenance pages when every cached page is deleted', function (): void {
    $paths = seedStaticArtefactsAndPage();

    $result = resolve(HtmlCacheStore::class)->deleteAll();

    expect($result->successful())->toBeTrue()
        ->and(Storage::disk('page_cache')->exists($paths['page']))->toBeFalse()
        ->and(Storage::disk('page_cache')->get($paths['error']))->toBe('<main>error</main>')
        ->and(Storage::disk('page_cache')->get($paths['maintenance']))->toBe('<main>maintenance</main>');
});

it('keeps the static error and maintenance pages when the whole page cache is cleared', function (): void {
    $paths = seedStaticArtefactsAndPage();
    $pageCache = resolve(PageCache::class);
    $pageCache->setCachePath(Storage::disk('page_cache')->path(''));
    File::put($pageCache->getCachePath('stray.html'), 'stray');

    expect($pageCache->clear())->toBeTrue()
        ->and(Storage::disk('page_cache')->exists($paths['page']))->toBeFalse()
        ->and(File::exists($pageCache->getCachePath('stray.html')))->toBeFalse()
        ->and(Storage::disk('page_cache')->get($paths['error']))->toBe('<main>error</main>')
        ->and(Storage::disk('page_cache')->get($paths['maintenance']))->toBe('<main>maintenance</main>');
});
