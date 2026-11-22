<?php

declare(strict_types=1);

use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\PublicRead\PublicArtefactPermit;
use Capell\Frontend\Support\Error\ErrorPagePathResolver;
use Capell\Frontend\Support\Maintenance\MaintenancePagePathResolver;
use Capell\HtmlCache\Actions\ClearCurrentOriginHtmlCacheAction;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Capell\HtmlCache\Support\Cache\HtmlCachePublicationGuard;
use Capell\HtmlCache\Support\Cache\HtmlCacheStore;
use Capell\HtmlCache\Support\Cache\PageCache;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Illuminate\Http\Request;
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

it('clears cached pages for the current request origin immediately and fences renders started before the clear', function (): void {
    $request = Request::create('https://example.test:8443/pricing');
    app()->instance('request', $request);
    config()->set('capell-html-cache.invalidation_mode', 'scheduled');

    $disk = Storage::disk('page_cache');
    $origin = '~port-8443/https.example.test';
    $page = $origin . '/pricing.html';
    $fragments = $page . PageCache::FRAGMENT_METADATA_EXTENSION;
    $error = $origin . '/error/404.html';
    $maintenance = $origin . '/maintenance/index.html';
    $otherPort = 'https.example.test/pricing.html';
    $otherHost = '~port-8443/https.other.test/pricing.html';

    foreach ([$page, $fragments, $error, $maintenance, $otherPort, $otherHost] as $path) {
        $disk->put($path, $path);
    }

    $guard = resolve(HtmlCachePublicationGuard::class);
    $token = $guard->capture($request);
    expect($token)->not->toBeNull();

    expect(ClearCurrentOriginHtmlCacheAction::run())->toBeTrue()
        ->and($disk->exists($page))->toBeFalse()
        ->and($disk->exists($fragments))->toBeFalse()
        ->and($disk->get($error))->toBe($error)
        ->and($disk->get($maintenance))->toBe($maintenance)
        ->and($disk->get($otherPort))->toBe($otherPort)
        ->and($disk->get($otherHost))->toBe($otherHost);

    $published = $guard->publish($token, static function (PublicArtefactPermit $permit) use ($page): bool {
        resolve(HtmlCacheStore::class)->put($permit, $page, 'stale render');

        return true;
    });

    expect($published)->toBeFalse()
        ->and($disk->exists($page))->toBeFalse();
    Queue::assertNothingPushed();
});

it('reports that a request origin has no cache directory to clear', function (): void {
    app()->instance('request', Request::create('https://no-cache.example.test/'));
    config()->set('capell-html-cache.enabled', false);

    expect(ClearCurrentOriginHtmlCacheAction::run())->toBeFalse();
});

it('does nothing when the runtime provider has not bound the page cache', function (): void {
    unset(app()[PageCache::class]);

    expect(ClearCurrentOriginHtmlCacheAction::run())->toBeFalse();
});
