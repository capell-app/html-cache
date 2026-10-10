<?php

declare(strict_types=1);

use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\HtmlCache\Actions\BuildHtmlCachePublicOutputSafetyDiagnosticsAction;
use Capell\HtmlCache\Actions\ClearAllHtmlCacheAction;
use Capell\HtmlCache\Actions\ClearCachedUrlAction;
use Capell\HtmlCache\Actions\DeletePageCacheAction;
use Capell\HtmlCache\Actions\GenerateStaticSiteAction;
use Capell\HtmlCache\Actions\MarkAllCachedUrlsStaleAction;
use Capell\HtmlCache\Actions\ProcessStaleHtmlCacheAction;
use Capell\HtmlCache\Actions\PurgeEdgeCacheAction;
use Capell\HtmlCache\Actions\RefreshOriginStaleCachedUrlAction;
use Capell\HtmlCache\Filament\Components\Tables\Columns\PageCachedIconColumn;
use Capell\HtmlCache\Filament\Concerns\HasPageCacheNotification;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Jobs\RefreshOriginStaleCachedUrlJob;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Models\HtmlCacheGenerationRun;
use Capell\HtmlCache\Models\StaleCachedUrl;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Capell\HtmlCache\Support\Cache\HtmlCacheStore;
use Capell\HtmlCache\Support\Cache\PageCache;
use Capell\HtmlCache\Support\StaticSite\StaticSiteExtensionRegistry;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Capell\Tests\Fixtures\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Filesystem\Filesystem as FilesystemContract;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

require_once __DIR__ . '/../Support/CachedModelUrlsTestSupport.php';

uses(HtmlCacheTestCase::class);

beforeEach(function (): void {
    Storage::fake('page_cache');
    Queue::fake();
});

function failureReportingCachedUrl(SiteDomain $domain, string $path = '/about'): CachedModelUrl
{
    $url = sprintf('%s://%s%s', $domain->scheme, $domain->domain, $path);

    return CachedModelUrl::query()->create([
        'url' => $url,
        'url_hash' => CachedModelUrl::hashUrl($url),
        'path' => $path,
        'site_id' => $domain->site_id,
        'site_domain_id' => $domain->getKey(),
        'language_id' => $domain->language_id,
        'cacheable_type' => $domain->getMorphClass(),
        'cacheable_id' => $domain->getKey(),
        'cached_at' => now(),
        'last_seen_at' => now(),
    ]);
}

function failureReportingStore(FilesystemContract $disk): HtmlCacheStore
{
    $manager = Mockery::mock(FilesystemManager::class);
    $manager->shouldReceive('disk')->with('page_cache')->andReturn($disk);

    return new HtmlCacheStore($manager);
}

it('rejects an inaccessible cache ancestor and retains invalidation metadata', function (string $operation): void {
    expect(posix_geteuid())->not->toBe(0);
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $row = failureReportingCachedUrl($domain);
    $ancestor = storage_path('framework/testing/inaccessible-' . bin2hex(random_bytes(8)));
    $root = $ancestor . '/page-cache';
    File::ensureDirectoryExists($root);
    File::put($root . '/old.html', 'old public HTML');
    $store = failureReportingStore(Storage::build(['driver' => 'local', 'root' => $root, 'throw' => true]));
    app()->instance(HtmlCacheStore::class, $store);
    chmod($ancestor, 0000);
    clearstatcache();

    try {
        expect(is_dir($root))->toBeFalse();
        expect(fn (): mixed => match ($operation) {
            'clear' => ClearAllHtmlCacheAction::run(),
            'directories' => $store->directories(),
            'files' => $store->files(),
            'allFiles' => $store->allFiles(''),
            'allDirectories' => $store->allDirectories(''),
            default => throw new InvalidArgumentException('Unknown cache enumeration operation.'),
        })->toThrow(RuntimeException::class);
        expect($row->fresh())->not->toBeNull();
        PurgeEdgeCacheAction::assertNotPushed();
    } finally {
        chmod($ancestor, 0700);
        clearstatcache();
        expect(File::get($root . '/old.html'))->toBe('old public HTML');
        File::deleteDirectory($ancestor);
    }
})->with(['clear', 'directories', 'files', 'allFiles', 'allDirectories']);

it('retains URL tracking when any page artefact cannot be deleted', function (bool $htmlDeletes, bool $metadataDeletes): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $row = failureReportingCachedUrl($domain);
    $path = resolve(HtmlCachePathResolver::class)->pathForUrl('/about', $domain);
    $disk = Mockery::mock(FilesystemContract::class);
    $disk->shouldReceive('path')->andReturnUsing(fn (string $file): string => Storage::disk('page_cache')->path($file));
    $disk->shouldReceive('exists')->andReturnUsing(fn (string $file): bool => in_array($file, [$path, $path . PageCache::FRAGMENT_METADATA_EXTENSION], true));
    $disk->shouldReceive('delete')->andReturnUsing(fn (string $file): bool => match ($file) {
        $path => $htmlDeletes,
        $path . PageCache::FRAGMENT_METADATA_EXTENSION => $metadataDeletes,
        default => true,
    });
    app()->instance(HtmlCacheStore::class, failureReportingStore($disk));

    expect(fn (): bool => ClearCachedUrlAction::run($row))->toThrow(RuntimeException::class);
    expect($row->fresh())->not->toBeNull();
    PurgeEdgeCacheAction::assertNotPushed();
})->with([[false, false], [false, true], [true, false]]);

it('clears orphaned tracking and purges the edge without guessing historical artefacts', function (bool $resolves, bool $selectedRow): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $row = failureReportingCachedUrl($domain);
    $path = resolve(HtmlCachePathResolver::class)->pathForRequestUrl($row->url, $domain);
    Storage::disk('page_cache')->put($path, 'old public HTML');
    $row->update(['site_domain_id' => null]);

    if (! $resolves) {
        $domain->updateQuietly(['domain' => 'moved.test']);
    }

    expect(ClearCachedUrlAction::run($selectedRow ? $row : $row->url))->toBeFalse();
    expect($row->fresh())->toBeNull()
        ->and(Storage::disk('page_cache')->get($path))->toBe('old public HTML');
    PurgeEdgeCacheAction::assertPushed();
})->with([true, false])->with([true, false]);

it('retains orphaned URL tracking when stale processing lacks a historical cache path', function (bool $hasCachePath, bool $hasErrorPath): void {
    config()->set('capell-html-cache.enabled', true);
    config()->set('capell-html-cache.write_enabled', true);
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $row = failureReportingCachedUrl($domain);
    $resolver = resolve(HtmlCachePathResolver::class);
    $path = $resolver->pathForRequestUrl($row->url, $domain);
    $errorPath = $resolver->pathForRequestUrl($row->url, $domain, error: true);
    Storage::disk('page_cache')->put($path, 'old public HTML');
    Storage::disk('page_cache')->put($errorPath, 'old error HTML');
    $row->update(['site_domain_id' => null]);
    $domain->updateQuietly(['domain' => 'moved.test']);
    MarkAllCachedUrlsStaleAction::run();
    $stale = StaleCachedUrl::query()->sole();
    $stale->update([
        'cache_path' => $hasCachePath ? $path : null,
        'error_cache_path' => $hasErrorPath ? $errorPath : null,
    ]);

    capell_artisan('capell:html-cache:process-stale')->assertFailed();

    expect($row->fresh())->not->toBeNull()
        ->and($stale->refresh()->status)->toBe(StaleCachedUrl::STATUS_FAILED)
        ->and($stale->last_error)->toContain('cache paths')
        ->and(Storage::disk('page_cache')->get($path))->toBe('old public HTML')
        ->and(Storage::disk('page_cache')->get($errorPath))->toBe('old error HTML');
    PurgeEdgeCacheAction::assertNotPushed();
})->with([[false, false], [false, true], [true, false]]);

it('fails global invalidation entry points when an individual deletion fails', function (string $entryPoint, bool $directory): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $row = failureReportingCachedUrl($domain);
    $disk = Mockery::mock(FilesystemContract::class);
    $disk->shouldReceive('path')->andReturn(Storage::disk('page_cache')->path(''));
    $disk->shouldReceive('directories')->once()->andReturn($directory ? ['old-pages'] : []);
    $disk->shouldReceive('files')->once()->andReturn($directory ? [] : ['old.html']);
    $disk->shouldReceive($directory ? 'deleteDirectory' : 'delete')->once()->andReturnFalse();
    app()->instance(HtmlCacheStore::class, failureReportingStore($disk));

    expect(fn (): mixed => match ($entryPoint) {
        'queued' => ClearAllHtmlCacheAction::makeJob()->handle(),
        'admin' => capell_artisan('capell:admin-clear-cache')->run(),
        default => throw new InvalidArgumentException('Unknown clear entry point.'),
    })->toThrow(RuntimeException::class, $directory ? 'old-pages' : 'old.html');
    expect($row->fresh())->not->toBeNull();
    PurgeEdgeCacheAction::assertNotPushed();
})->with(['queued', 'admin'])->with([true, false]);

it('reports refresh notification failures without closing the retry notification', function (bool $targeted): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $row = failureReportingCachedUrl($domain);
    $path = resolve(HtmlCachePathResolver::class)->pathForRequestUrl($row->url, $domain);
    $disk = Mockery::mock(FilesystemContract::class);
    $disk->shouldReceive('path')->andReturnUsing(fn (string $file): string => Storage::disk('page_cache')->path($file));
    $disk->shouldReceive('directories')->andReturn([]);
    $disk->shouldReceive('files')->andReturn([$path]);
    $disk->shouldReceive('exists')->andReturnTrue();
    $disk->shouldReceive('delete')->andReturnFalse();
    app()->instance(HtmlCacheStore::class, failureReportingStore($disk));
    $component = new class
    {
        use HasPageCacheNotification;

        /** @var list<string> */
        public array $events = [];

        public function dispatch(string $event, mixed ...$params): void
        {
            $this->events[] = $event;
        }
    };

    $component->refreshPageCache($targeted ? [$row->url] : null);

    Notification::assertNotified(Notification::make()->title(__('capell-html-cache::admin.clear_failed'))->danger());
    expect($component->events)->toBe([])
        ->and($row->fresh())->not->toBeNull();
    PurgeEdgeCacheAction::assertNotPushed();
})->with([true, false]);

it('does not report page deletion success when a URL cannot be cleared', function (): void {
    $pageUrl = new PageUrl(['url' => '/missing']);
    $pageUrl->setRelation('siteDomain', new SiteDomain(['scheme' => 'https', 'domain' => 'missing.test']));

    expect(fn (): mixed => DeletePageCacheAction::makeJob($pageUrl, false)->handle())
        ->toThrow(RuntimeException::class, 'Unable to clear');
});

it('reports the page cache column clear as queued until the job executes', function (): void {
    $pageUrl = new PageUrl;
    $column = PageCachedIconColumn::make('cached');
    new ReflectionMethod($column, 'deleteCacheFile')->invoke($column, $pageUrl);

    DeletePageCacheAction::assertPushed();
    Notification::assertNotified(Notification::make()->title(__('capell-html-cache::admin.clear_queued'))->info());
});

it('fails the targeted clear command when an existing representation cannot be deleted', function (string $failedSuffix): void {
    $files = Mockery::mock(Filesystem::class);
    $files->shouldReceive('exists')->andReturnUsing(fn (string $file): bool => str_ends_with($file, '.html') || str_ends_with($file, '.fragments.json'));
    $files->shouldReceive('delete')->andReturnUsing(fn (string $file): bool => ! str_ends_with($file, $failedSuffix));
    $cache = new PageCache($files)->setCachePath(Storage::disk('page_cache')->path(''));
    app()->instance(PageCache::class, $cache);

    capell_artisan('capell:html-cache:clear', ['slug' => 'about'])
        ->doesntExpectOutput('HTML cache cleared for "about".')
        ->doesntExpectOutput('No HTML cache found for "about".')
        ->assertFailed();
})->with(['about.html', '.fragments.json', '.404.html']);

it('fails recursive clearing when the filesystem leaves an artefact behind', function (): void {
    $disk = Storage::disk('page_cache');
    $disk->put('nested/old.html', 'old HTML');

    $files = Mockery::mock(Filesystem::class)->makePartial();
    $files->shouldReceive('deleteDirectory')->with($disk->path('nested'), true)->andReturnTrue();
    app()->instance(PageCache::class, new PageCache($files)->setCachePath($disk->path('')));

    capell_artisan('capell:html-cache:clear', ['slug' => 'nested', '--recursive' => true])->assertFailed();
    expect($disk->get('nested/old.html'))->toBe('old HTML');
});

it('fails invalid internal generation before marking the site completed', function (): void {
    config()->set('capell-html-cache.static_generation.internal_requests', true);
    $domain = SiteDomain::factory()->create();
    resolve(StaticSiteExtensionRegistry::class)->register('invalid-page', function (Site $site, SiteDomain $siteDomain, Closure $visit): void {
        $visit('/relative-only');
    });
    $run = HtmlCacheGenerationRun::query()->create(['status' => HtmlCacheGenerationRun::STATUS_RUNNING, 'total_sites' => 1, 'started_at' => now()]);

    expect(fn () => GenerateStaticSiteAction::run($domain->site, $run->id))->toThrow(RuntimeException::class);
    expect($run->refresh()->status)->toBe(HtmlCacheGenerationRun::STATUS_FAILED)
        ->and($run->completed_sites)->toBe(0);
});

it('records failed external generation without completing the site', function (string $url, int $status, bool $sent): void {
    config()->set('capell-html-cache.static_generation.internal_requests', false);
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => '8.8.8.8', 'path' => null, 'status' => true]);
    Http::fake(['*' => Http::response('unusable page', $status)]);
    resolve(StaticSiteExtensionRegistry::class)->register('required-page', function (Site $site, SiteDomain $siteDomain, Closure $visit) use ($url): void {
        $visit($url);
    });
    $run = HtmlCacheGenerationRun::query()->create(['status' => HtmlCacheGenerationRun::STATUS_RUNNING, 'total_sites' => 1, 'started_at' => now()]);

    expect(fn () => GenerateStaticSiteAction::run($domain->site, $run->id))->toThrow(RuntimeException::class);
    expect($run->refresh()->status)->toBe(HtmlCacheGenerationRun::STATUS_FAILED)
        ->and($run->completed_sites)->toBe(0)
        ->and($run->failed_sites)->toBe(1);
    Http::assertSentCount($sent ? 1 : 0);
})->with([
    ['https://8.8.8.8/required', 302, true],
    ['https://8.8.8.8/required', 404, true],
    ['https://8.8.8.8/required', 500, true],
    ['https://127.0.0.1/required', 200, false],
    ['file:///required', 200, false],
    ['https://unlisted.invalid/required', 200, false],
]);

it('completes external generation only after successful requests', function (): void {
    config()->set('capell-html-cache.static_generation.internal_requests', false);
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => '8.8.8.8', 'path' => null, 'status' => true]);
    Http::fake(['*' => Http::response('public page', 200)]);
    resolve(StaticSiteExtensionRegistry::class)->register('required-page', function (Site $site, SiteDomain $siteDomain, Closure $visit): void {
        $visit('https://8.8.8.8/required');
    });
    $run = HtmlCacheGenerationRun::query()->create(['status' => HtmlCacheGenerationRun::STATUS_RUNNING, 'total_sites' => 1, 'started_at' => now()]);

    GenerateStaticSiteAction::run($domain->site, $run->id);

    expect($run->refresh()->status)->toBe(HtmlCacheGenerationRun::STATUS_COMPLETED)
        ->and($run->completed_sites)->toBe(1)
        ->and($run->failed_sites)->toBe(0);
    Http::assertSentCount(1);
});

it('does not reuse a successful response for a subsequent rejected destination', function (): void {
    config()->set('capell-html-cache.static_generation.internal_requests', false);
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => '8.8.8.8', 'path' => null, 'status' => true]);
    Http::fake(['*' => Http::response('public page', 200)]);
    resolve(StaticSiteExtensionRegistry::class)->register('required-pages', function (Site $site, SiteDomain $siteDomain, Closure $visit): void {
        $visit('https://8.8.8.8/first');
        $visit('https://127.0.0.1/rejected');
    });
    $run = HtmlCacheGenerationRun::query()->create(['status' => HtmlCacheGenerationRun::STATUS_RUNNING, 'total_sites' => 1, 'started_at' => now()]);

    expect(fn () => GenerateStaticSiteAction::run($domain->site, $run->id))->toThrow(RuntimeException::class);
    expect($run->refresh()->status)->toBe(HtmlCacheGenerationRun::STATUS_FAILED)
        ->and($run->completed_sites)->toBe(0);
    Http::assertSentCount(1);
});

it('fails stale refresh commands when any attempted URL fails', function (string $command, bool $mixed): void {
    bindHtmlCacheFrontendContext();
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $paths = $mixed ? ['/failed', '/ok'] : ['/failed'];

    foreach ($paths as $path) {
        failureReportingCachedUrl($domain, $path);
        Storage::disk('page_cache')->put(resolve(HtmlCachePathResolver::class)->pathForUrl($path, $domain), 'old HTML');
    }

    $kernel = Mockery::mock(Kernel::class);
    $kernel->shouldReceive('handle')->andReturnUsing(static fn (Request $request): Response => resolve(HtmlCacheMiddleware::class)->handle(
        $request,
        static fn (): Response => new Response('fresh HTML', $request->path() === 'failed' ? 500 : 200, ['Content-Type' => 'text/html', 'Cache-Control' => 'public']),
    ));
    $kernel->shouldReceive('terminate');
    app()->instance(Kernel::class, $kernel);
    MarkAllCachedUrlsStaleAction::run();

    capell_artisan($command, $command === 'capell:html-cache:clear' ? ['--process' => true] : [])
        ->expectsOutputToContain(sprintf('Refreshed %d stale HTML cache URL(s); 1 failed', $mixed ? 1 : 0))
        ->assertFailed();

    expect(StaleCachedUrl::query()->where('status', StaleCachedUrl::STATUS_FAILED)->count())->toBe(1)
        ->and(StaleCachedUrl::query()->where('status', StaleCachedUrl::STATUS_PROCESSED)->count())->toBe($mixed ? 1 : 0)
        ->and(Storage::disk('page_cache')->get(resolve(HtmlCachePathResolver::class)->pathForUrl('/failed', $domain)))->toBe('old HTML');
})->with(['capell:html-cache:process-stale', 'capell:html-cache:clear'])->with([false, true]);

it('reports separate stale refresh outcomes while respecting retry backoff', function (): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    failureReportingCachedUrl($domain);
    MarkAllCachedUrlsStaleAction::run();
    $kernel = Mockery::mock(Kernel::class);
    $kernel->shouldReceive('handle')->once()->andReturn(new Response('failed', Response::HTTP_INTERNAL_SERVER_ERROR));
    $kernel->shouldReceive('terminate')->once();
    app()->instance(Kernel::class, $kernel);

    $result = ProcessStaleHtmlCacheAction::run(1);

    expect($result)->toHaveProperties(['attempted' => 1, 'succeeded' => 0, 'failed' => 1, 'deferred' => 0, 'notApplicable' => 0]);
    expect(ProcessStaleHtmlCacheAction::run(1))->toHaveProperties(['attempted' => 0, 'succeeded' => 0, 'failed' => 0]);
});

it('fails queued stale refresh entry points after recording a failed attempt', function (string $entryPoint): void {
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $row = failureReportingCachedUrl($domain);
    $path = resolve(HtmlCachePathResolver::class)->pathForRequestUrl($row->url, $domain);
    Storage::disk('page_cache')->put($path, 'old public HTML');
    MarkAllCachedUrlsStaleAction::run();
    $kernel = Mockery::mock(Kernel::class);
    $kernel->shouldReceive('handle')->once()->andReturn(new Response('failed', Response::HTTP_INTERNAL_SERVER_ERROR));
    $kernel->shouldReceive('terminate')->once();
    app()->instance(Kernel::class, $kernel);

    expect(fn (): mixed => match ($entryPoint) {
        'batch' => ProcessStaleHtmlCacheAction::makeJob(1)->handle(),
        'origin action' => RefreshOriginStaleCachedUrlAction::makeJob($row->url)->handle(),
        'origin job' => new RefreshOriginStaleCachedUrlJob($row->url)->handle(),
        default => throw new InvalidArgumentException('Unknown refresh entry point.'),
    })->toThrow(RuntimeException::class);
    $stale = StaleCachedUrl::query()->sole();
    expect($stale->status)->toBe(StaleCachedUrl::STATUS_FAILED)
        ->and($stale->attempts)->toBe(1)
        ->and($stale->last_error)->toContain('500')
        ->and($row->fresh())->not->toBeNull()
        ->and(Storage::disk('page_cache')->get($path))->toBe('old public HTML');
    PurgeEdgeCacheAction::assertNotPushed();
})->with(['batch', 'origin action', 'origin job']);

it('reports incomplete safety diagnostics when unindexed enumeration fails', function (string $failedMethod): void {
    $user = User::factory()->create();
    $user->assignRole('super_admin');

    test()->actingAs($user);
    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $row = failureReportingCachedUrl($domain);
    $path = resolve(HtmlCachePathResolver::class)->pathForRequestUrl($row->url, $domain);
    $actualDisk = Storage::disk('page_cache');
    $actualDisk->put($path, '<main>safe public HTML</main>');

    $disk = Mockery::mock(FilesystemContract::class);
    $disk->shouldReceive('path')->andReturnUsing($actualDisk->path(...));
    $disk->shouldReceive('exists')->andReturnUsing($actualDisk->exists(...));
    $disk->shouldReceive($failedMethod)->andThrow(new RuntimeException('Permission denied'));
    $disk->shouldReceive($failedMethod === 'files' ? 'allFiles' : 'files')->andReturn([]);
    app()->instance(HtmlCacheStore::class, failureReportingStore($disk));

    $checks = BuildHtmlCachePublicOutputSafetyDiagnosticsAction::run();

    expect(collect($checks)->pluck('status')->all())->toContain('amber')->not->toContain('green');
})->with(['files', 'allFiles']);
