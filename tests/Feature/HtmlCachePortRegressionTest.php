<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\Core\Models\SiteDomain;
use Capell\HtmlCache\Actions\BuildHtmlCacheEligibilityReportAction;
use Capell\HtmlCache\Actions\ClearCachedUrlsForModelAction;
use Capell\HtmlCache\Actions\ClearCachedUrlsForSurrogateKeysAction;
use Capell\HtmlCache\Actions\WriteRefreshedHtmlCacheFileAction;
use Capell\HtmlCache\Jobs\RegisterCachedModelUrlsJob;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Models\StaleCachedUrl;
use Capell\HtmlCache\Support\Cache\HtmlCacheStore;
use Capell\HtmlCache\Support\Cache\PageCache;
use Capell\HtmlCache\Support\ModelServing\RetrievedModelStore;
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
    config()->set('capell-html-cache.minify_html', false);
});

it('clears every port copy while dependency registration is pending', function (string $invalidation): void {
    config()->set('capell-html-cache.model_event_registration_mode', 'async');
    $domain = SiteDomain::factory()->create(['scheme' => 'http', 'domain' => 'pending.test', 'port' => 8080, 'path' => null]);
    SiteDomain::factory()->create(['scheme' => 'http', 'domain' => 'pending.test', 'port' => 8081, 'path' => null, 'site_id' => $domain->site_id, 'language_id' => $domain->language_id]);
    $page = (new Page)->forceFill(['id' => 101]);
    $url = 'http://pending.test:8080/shared-page';
    CachedModelUrl::query()->create([
        'url' => $url, 'url_hash' => CachedModelUrl::hashUrl($url), 'path' => '/shared-page',
        'site_id' => $domain->site_id, 'site_domain_id' => $domain->id, 'language_id' => $domain->language_id,
        'cacheable_type' => $page->getMorphClass(), 'cacheable_id' => $page->id,
        'cached_at' => now(), 'last_seen_at' => now(),
    ]);

    foreach ([8080, 8081] as $port) {
        $request = Request::create('http://pending.test:' . $port . '/shared-page');
        app()->instance('request', $request);
        expect(resolve(PageCache::class)->cache($this->beginCacheRender($request), response('old model content', headers: ['Content-Type' => 'text/html'])))->toBeTrue();
        Storage::disk('page_cache')->put('~port-' . $port . '/http.pending.test/neighbour.html', 'neighbour');
        if ($port === 8081) {
            $tracker = new RetrievedModelStore;
            $tracker->track($page);
            $tracker->flushToUrl($request->getUri());
            Queue::assertPushed(RegisterCachedModelUrlsJob::class, 1);
            expect(CachedModelUrl::query()->where('url', $request->getUri())->exists())->toBeFalse();
        }
    }

    $cleared = $invalidation === 'model'
        ? ClearCachedUrlsForModelAction::run($page)
        : ClearCachedUrlsForSurrogateKeysAction::run(['page-' . $page->id]);
    expect($cleared)->toBe(1);
    foreach ([8080, 8081] as $port) {
        $request = Request::create('http://pending.test:' . $port . '/shared-page');
        app()->instance('request', $request);
        expect(resolve(PageCache::class)->getCachePage($request))->toBeFalse()
            ->and(Storage::disk('page_cache')->get('~port-' . $port . '/http.pending.test/neighbour.html'))->toBe('neighbour');
    }
})->with(['model', 'page surrogate']);

it('clears unindexed site port copies without changing standard roots or another mounted site', function (): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'http', 'domain' => 'unindexed.test', 'port' => 8080, 'path' => '/site']);
    $disk = Storage::disk('page_cache');
    $disk->put('http.unindexed.test/site/page.html', 'standard snapshot');
    foreach ([8080, 8081] as $port) {
        $request = Request::create('http://unindexed.test:' . $port . '/site/page');
        app()->instance('request', $request);
        expect(resolve(PageCache::class)->cache($this->beginCacheRender($request), response('old unindexed content', headers: ['Content-Type' => 'text/html'])))->toBeTrue();
        $disk->put('~port-' . $port . '/http.unindexed.test/other/page.html', 'other site');
    }

    expect(CachedModelUrl::query()->count())->toBe(0);
    ClearCachedUrlsForSurrogateKeysAction::run(['site-' . $domain->site_id]);

    foreach ([8080, 8081] as $port) {
        $request = Request::create('http://unindexed.test:' . $port . '/site/page');
        app()->instance('request', $request);
        expect(resolve(PageCache::class)->getCachePage($request))->toBeFalse()
            ->and($disk->get('~port-' . $port . '/http.unindexed.test/other/page.html'))->toBe('other site');
    }

    expect($disk->get('http.unindexed.test/site/page.html'))->toBe('standard snapshot');
});

it('retains the ambient standard-port diagnostic read root across schemes', function (string $ambientScheme, string $targetScheme): void {
    $ambient = Request::create($ambientScheme . '://example.test/');
    app()->instance('request', $ambient);
    Storage::disk('page_cache')->put($targetScheme . '.example.test/form.html', 'target snapshot');

    expect(BuildHtmlCacheEligibilityReportAction::run(Request::create($targetScheme . '://example.test/form'))->cacheState)->toBe('missing')
        ->and(request())->toBe($ambient);
    Storage::disk('page_cache')->put($ambientScheme . '.example.test/form.html', 'ambient snapshot');
    expect(BuildHtmlCacheEligibilityReportAction::run(Request::create($targetScheme . '://example.test/form'))->cacheState)->toBe('hit')
        ->and(request())->toBe($ambient);
})->with([['http', 'https'], ['https', 'http']]);

it('fences an unindexed port render that has not created its origin directory', function (): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'http', 'domain' => 'inflight.test', 'port' => 8080, 'path' => null]);
    $request = Request::create('http://inflight.test:8081/page');
    app()->instance('request', $request);
    $this->beginCacheRender($request);

    ClearCachedUrlsForSurrogateKeysAction::run(['site-' . $domain->site_id]);

    expect(resolve(PageCache::class)->cache($request, response('old in-flight content', headers: ['Content-Type' => 'text/html'])))->toBeFalse()
        ->and(Storage::disk('page_cache')->exists('~port-8081/http.inflight.test/page.html'))->toBeFalse();
});

it('refuses cache operations through a symlinked port directory', function (string $operation): void {
    $disk = Storage::disk('page_cache');
    $outside = sys_get_temp_dir() . '/html-cache-outside-' . bin2hex(random_bytes(8));
    File::ensureDirectoryExists($outside);
    File::ensureDirectoryExists($disk->path('~port-8080'));
    expect(symlink($outside, $disk->path('~port-8080/http.example.test')))->toBeTrue();
    $request = Request::create('http://example.test:8080/nested/page');
    app()->instance('request', $request);
    $this->beginCacheRender($request);
    $relative = '~port-8080/http.example.test/nested/page.html';

    try {
        if ($operation === 'refresh') {
            $stale = StaleCachedUrl::query()->create([
                'stale_key' => hash('sha256', $request->getUri()), 'url' => $request->getUri(),
                'url_hash' => CachedModelUrl::hashUrl($request->getUri()), 'path' => '/nested/page',
                'cache_path' => 'http.example.test/nested/page.html', 'reason' => 'manual',
                'status' => StaleCachedUrl::STATUS_PROCESSING, 'claim_token' => 'refresh-claim',
            ]);
            expect(fn (): bool => WriteRefreshedHtmlCacheFileAction::run(response('escaped'), $stale, $request))->toThrow(RuntimeException::class);
        } elseif ($operation === 'publish') {
            expect(fn (): PageCache => resolve(PageCache::class))->toThrow(RuntimeException::class);
        } elseif (in_array($operation, ['put', 'replace'], true)) {
            expect(fn () => resolve(HtmlCacheStore::class)->{$operation}($relative, 'escaped'))->toThrow(RuntimeException::class);
        } else {
            File::ensureDirectoryExists($outside . '/nested');
            File::put($outside . '/nested/page.html', 'keep');
            $store = resolve(HtmlCacheStore::class);
            expect(fn () => match ($operation) {
                'delete' => $store->delete($relative),
                'delete page' => $store->deletePage($relative),
                'delete directory' => $store->deleteDirectory(dirname($relative)),
                default => throw new InvalidArgumentException("Unhandled operation [{$operation}]."),
            })->toThrow(RuntimeException::class);
            expect(File::get($outside . '/nested/page.html'))->toBe('keep');
        }

        expect(File::allFiles($outside))->toHaveCount(in_array($operation, ['delete', 'delete page', 'delete directory'], true) ? 1 : 0);
    } finally {
        File::delete($disk->path('~port-8080/http.example.test'));
        File::deleteDirectory($outside);
    }
})->with(['refresh', 'publish', 'put', 'replace', 'delete', 'delete page', 'delete directory']);

it('keeps native port-scoped forgetting out of symlinked descendants', function (): void {
    $request = Request::create('http://example.test:8080/nested/page');
    app()->instance('request', $request);
    $cache = resolve(PageCache::class);
    $outside = sys_get_temp_dir() . '/html-cache-forget-' . bin2hex(random_bytes(8));
    File::ensureDirectoryExists($outside);
    File::put($outside . '/page.html', 'keep');
    $link = $cache->getCachePath('nested');
    expect(symlink($outside, $link))->toBeTrue();
    try {
        expect($cache->forget('nested/page'))->toBeFalse()
            ->and(File::get($outside . '/page.html'))->toBe('keep');
    } finally {
        File::delete($link);
        File::deleteDirectory($outside);
    }
});

it('rejects an outside parent even when the final file links back inside the disk', function (): void {
    $disk = Storage::disk('page_cache');
    $disk->put('http.example.test/keep.html', 'keep');

    $outside = sys_get_temp_dir() . '/html-cache-parent-' . bin2hex(random_bytes(8));
    File::ensureDirectoryExists($outside);
    File::ensureDirectoryExists($disk->path('~port-8080'));
    $link = $disk->path('~port-8080/http.example.test');
    expect(symlink($outside, $link))->toBeTrue()
        ->and(symlink($disk->path('http.example.test/keep.html'), $outside . '/page.html'))->toBeTrue();
    $request = Request::create('http://example.test:8080/page');
    app()->instance('request', $request);
    $this->beginCacheRender($request);
    $stale = StaleCachedUrl::query()->create([
        'stale_key' => hash('sha256', $request->getUri()), 'url' => $request->getUri(),
        'url_hash' => CachedModelUrl::hashUrl($request->getUri()), 'path' => '/page',
        'cache_path' => 'http.example.test/page.html', 'reason' => 'manual',
        'status' => StaleCachedUrl::STATUS_PROCESSING, 'claim_token' => 'refresh-claim',
    ]);
    try {
        expect(fn (): bool => WriteRefreshedHtmlCacheFileAction::run(response('escaped'), $stale, $request))->toThrow(RuntimeException::class);
        expect(is_link($outside . '/page.html'))->toBeTrue()
            ->and($disk->get('http.example.test/keep.html'))->toBe('keep');
    } finally {
        File::delete($link);
        File::deleteDirectory($outside);
    }
});

it('preserves a different site sharing the host on another port', function (string $invalidation, bool $indexed, int $targetPort, ?string $targetMount, ?string $otherMount): void {
    $otherPort = $targetPort === 8080 ? 8081 : 8080;
    $target = SiteDomain::factory()->create(['scheme' => 'http', 'domain' => 'shared.test', 'port' => $targetPort, 'path' => $targetMount]);
    $other = SiteDomain::factory()->create(['scheme' => 'http', 'domain' => 'shared.test', 'port' => $otherPort, 'path' => $otherMount]);
    expect($target->site_id)->not->toBe($other->site_id);
    $page = (new Page)->forceFill(['id' => 902]);
    $otherRow = null;
    foreach ([$target, $other] as $domain) {
        // Both sites can cache the same request path even with different mounts.
        $url = 'http://shared.test:' . $domain->port . '/site/page';
        if ($indexed) {
            $row = CachedModelUrl::query()->create([
                'url' => $url, 'url_hash' => CachedModelUrl::hashUrl($url), 'path' => '/site/page',
                'site_id' => $domain->site_id, 'site_domain_id' => $domain->id, 'language_id' => $domain->language_id,
                'cacheable_type' => $page->getMorphClass(), 'cacheable_id' => $domain->is($target) ? $page->id : 903,
                'cached_at' => now(), 'last_seen_at' => now(),
            ]);
            if ($domain->is($other)) {
                $otherRow = $row;
            }
        }

        $request = Request::create($url);
        app()->instance('request', $request);
        expect(resolve(PageCache::class)->cache($this->beginCacheRender($request), response($domain->is($target) ? 'target site' : 'other site', headers: ['Content-Type' => 'text/html'])))->toBeTrue();
    }

    match ($invalidation) {
        'model' => ClearCachedUrlsForModelAction::run($page),
        'page' => ClearCachedUrlsForSurrogateKeysAction::run(['page-' . $page->id]),
        'site' => ClearCachedUrlsForSurrogateKeysAction::run(['site-' . $target->site_id]),
        default => throw new InvalidArgumentException('Unknown invalidation entry point.'),
    };
    $request = Request::create('http://shared.test:' . $otherPort . '/site/page');
    app()->instance('request', $request);
    expect(resolve(PageCache::class)->getCachePage($request))->toBe('other site');
    if ($otherRow instanceof CachedModelUrl) {
        expect(CachedModelUrl::query()->whereKey($otherRow->id)->exists())->toBeTrue();
    }

    $request = Request::create('http://shared.test:' . $targetPort . '/site/page');
    app()->instance('request', $request);
    expect(resolve(PageCache::class)->getCachePage($request))->toBeFalse();
})->with([
    'model' => ['model', true], 'page' => ['page', true],
    'indexed site' => ['site', true], 'unindexed site' => ['site', false],
])->with([
    'root to root' => [8080, null, null], 'reverse roots' => [8081, null, null],
    'mount to root' => [8080, '/site', null], 'root to mount' => [8081, null, '/site'],
]);

it('clears both configured ports for the same site without either index', function (): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'http', 'domain' => 'same-site.test', 'port' => 8080, 'path' => '/site']);
    SiteDomain::factory()->create(['scheme' => 'http', 'domain' => 'same-site.test', 'port' => 8081, 'path' => '/site', 'site_id' => $domain->site_id, 'language_id' => $domain->language_id]);
    foreach ([8080, 8081] as $port) {
        $request = Request::create('http://same-site.test:' . $port . '/site/page');
        app()->instance('request', $request);
        expect(resolve(PageCache::class)->cache($this->beginCacheRender($request), response('old', headers: ['Content-Type' => 'text/html'])))->toBeTrue();
    }

    expect(CachedModelUrl::query()->count())->toBe(0)
        ->and(ClearCachedUrlsForSurrogateKeysAction::run(['site-' . $domain->site_id]))->toBe(2);
    foreach ([8080, 8081] as $port) {
        $request = Request::create('http://same-site.test:' . $port . '/site/page');
        app()->instance('request', $request);
        expect(resolve(PageCache::class)->getCachePage($request))->toBeFalse();
    }
});

it('preserves ambiguous orphan ports and honours indexed ownership', function (bool $indexed): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'http', 'domain' => 'orphan.test', 'port' => 8080, 'path' => null]);
    SiteDomain::factory()->create(['scheme' => 'http', 'domain' => 'orphan.test', 'port' => 8081, 'path' => null]);
    $url = 'http://orphan.test:8082/page';
    if ($indexed) {
        CachedModelUrl::query()->create([
            'url' => $url, 'url_hash' => CachedModelUrl::hashUrl($url), 'path' => '/page',
            'site_id' => $domain->site_id, 'site_domain_id' => $domain->id, 'language_id' => $domain->language_id,
            'cacheable_type' => Page::class, 'cacheable_id' => 902,
            'cached_at' => now(), 'last_seen_at' => now(),
        ]);
    }

    $request = Request::create($url);
    app()->instance('request', $request);
    expect(resolve(PageCache::class)->cache($this->beginCacheRender($request), response('orphan content', headers: ['Content-Type' => 'text/html'])))->toBeTrue();
    ClearCachedUrlsForSurrogateKeysAction::run(['site-' . $domain->site_id]);
    expect(resolve(PageCache::class)->getCachePage($request))->toBe($indexed ? false : 'orphan content');
})->with([false, true]);

it('preserves indexed orphans belonging to another site after its domain changes', function (string $invalidation, string $targetPath, string $otherPath): void {
    $target = SiteDomain::factory()->create(['scheme' => 'http', 'domain' => 'historical.test', 'port' => 8080, 'path' => null]);
    $other = SiteDomain::factory()->create(['scheme' => 'http', 'domain' => 'historical.test', 'port' => 8082, 'path' => null]);
    $page = (new Page)->forceFill(['id' => 902]);
    $otherRow = null;
    foreach ([$target, $other] as $domain) {
        $url = 'http://historical.test:' . $domain->port . ($domain->is($target) ? $targetPath : $otherPath);
        $row = CachedModelUrl::query()->create([
            'url' => $url, 'url_hash' => CachedModelUrl::hashUrl($url), 'path' => Request::create($url)->getPathInfo(),
            'site_id' => $domain->site_id, 'site_domain_id' => $domain->id, 'language_id' => $domain->language_id,
            'cacheable_type' => $page->getMorphClass(), 'cacheable_id' => $domain->is($target) ? $page->id : 903,
            'cached_at' => now(), 'last_seen_at' => now(),
        ]);
        if ($domain->is($other)) {
            $otherRow = $row;
        }

        $request = Request::create($url);
        app()->instance('request', $request);
        expect(resolve(PageCache::class)->cache($this->beginCacheRender($request), response('keep historical owner', headers: ['Content-Type' => 'text/html'])))->toBeTrue();
    }

    $other->update(['domain' => 'moved.test']);
    match ($invalidation) {
        'model' => ClearCachedUrlsForModelAction::run($page),
        'page' => ClearCachedUrlsForSurrogateKeysAction::run(['page-' . $page->id]),
        'site' => ClearCachedUrlsForSurrogateKeysAction::run(['site-' . $target->site_id]),
        default => throw new InvalidArgumentException('Unknown invalidation entry point.'),
    };
    $request = Request::create('http://historical.test:8082' . $otherPath);
    app()->instance('request', $request);
    expect(resolve(PageCache::class)->getCachePage($request))->toBe('keep historical owner')
        ->and(CachedModelUrl::query()->whereKey($otherRow?->id)->exists())->toBeTrue();
})->with(['model', 'page', 'site'])->with([
    'page' => ['/page', '/page'], 'homepage alias' => ['/index', '/'],
]);

it('clears port copies when the disk root is reached through a symlink', function (bool $ancestor): void {
    $base = sys_get_temp_dir() . '/html-cache-linked-root-' . bin2hex(random_bytes(8));
    File::ensureDirectoryExists($base . '/real/disk');
    expect(symlink($ancestor ? $base . '/real' : $base . '/real/disk', $base . '/alias'))->toBeTrue();
    config()->set('filesystems.disks.page_cache', ['driver' => 'local', 'root' => $base . '/alias' . ($ancestor ? '/disk' : ''), 'throw' => true]);
    Storage::forgetDisk('page_cache');
    app()->forgetInstance(HtmlCacheStore::class);
    $domain = SiteDomain::factory()->create(['scheme' => 'http', 'domain' => 'linked.test', 'port' => 8080, 'path' => null]);
    $request = Request::create('http://linked.test:8080/page');
    app()->instance('request', $request);
    try {
        $store = resolve(HtmlCacheStore::class);
        $store->put('~port-8080/http.linked.test/page.html', 'first');
        expect(resolve(PageCache::class)->getCachePage($request))->toBe('first');
        $store->replace('~port-8080/http.linked.test/page.html', 'replacement');
        expect(resolve(PageCache::class)->getCachePage($request))->toBe('replacement');
        expect(resolve(PageCache::class)->cache($this->beginCacheRender($request), response('old', headers: ['Content-Type' => 'text/html'])))->toBeTrue();
        expect(CachedModelUrl::query()->count())->toBe(0)
            ->and(ClearCachedUrlsForSurrogateKeysAction::run(['site-' . $domain->site_id]))->toBe(1)
            ->and(resolve(PageCache::class)->getCachePage($request))->toBeFalse();
        $store->put('~port-8080/http.linked.test/page.html', 'delete me');
        expect($store->deletePage('~port-8080/http.linked.test/page.html', '~port-8080/http.linked.test'))->toBeTrue()
            ->and(resolve(PageCache::class)->getCachePage($request))->toBeFalse();

        File::ensureDirectoryExists($base . '/outside');
        File::put($base . '/outside/page.html', 'keep');
        $link = Storage::disk('page_cache')->path('~port-8081');
        expect(symlink($base . '/outside', $link))->toBeTrue();
        expect(fn () => $store->put('~port-8081/page.html', 'escaped'))->toThrow(RuntimeException::class);
        expect(fn () => $store->delete('~port-8081/page.html'))->toThrow(RuntimeException::class);
        expect(fn () => $store->deleteDirectory('~port-8081'))->toThrow(RuntimeException::class);
        expect($store->portDirectories('http.linked.test'))->not->toContain('~port-8081/http.linked.test')
            ->and(File::get($base . '/outside/page.html'))->toBe('keep');
        File::delete($link);
    } finally {
        File::delete($base . '/alias');
        File::deleteDirectory($base);
    }
})->with([true, false]);
