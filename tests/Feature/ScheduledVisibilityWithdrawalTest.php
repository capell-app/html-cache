<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\SiteDomain;
use Capell\HtmlCache\Actions\ClearAllHtmlCacheAction;
use Capell\HtmlCache\Actions\WithdrawCachedHtmlForOpenedAccessAreasAction;
use Capell\HtmlCache\Contracts\CachePurger;
use Capell\HtmlCache\Data\EdgeCachePurgeData;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Capell\HtmlCache\Support\Cache\HtmlCachePublicationGuard;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

uses(HtmlCacheTestCase::class);

it('withdraws scheduled-cache files and fences in-flight renders when a page becomes private or is deleted', function (string $change, bool $indexed): void {
    Storage::fake('page_cache');
    config()->set('capell-html-cache.invalidation.mode', 'scheduled');
    config()->set('queue.default', 'sync');

    [$domain, $page] = Model::withoutEvents(function (): array {
        $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'withdrawal.example.test', 'path' => null]);
        $page = Page::factory()->recycle($domain->site)->withTranslations()->create(['visible_from' => now()->subDay(), 'visible_until' => null]);
        PageUrl::factory()->page($page)->site($domain->site)->language($domain->language)->create(['url' => '/withdrawn']);

        return [$domain, $page];
    });
    $url = 'https://withdrawal.example.test/withdrawn';
    $path = resolve(HtmlCachePathResolver::class)->pathForUrl('/withdrawn', $domain);
    $unrelatedPath = resolve(HtmlCachePathResolver::class)->pathForUrl('/unrelated', $domain);
    Storage::disk('page_cache')->put($path, 'Previously public content');
    Storage::disk('page_cache')->put($unrelatedPath, 'Unrelated public content');
    if ($indexed) {
        CachedModelUrl::query()->create([
            'url' => $url,
            'url_hash' => CachedModelUrl::hashUrl($url),
            'path' => '/withdrawn',
            'site_id' => $domain->site_id,
            'site_domain_id' => $domain->getKey(),
            'language_id' => $domain->language_id,
            'cacheable_type' => $page->getMorphClass(),
            'cacheable_id' => $page->getKey(),
            'cached_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    $guard = resolve(HtmlCachePublicationGuard::class);
    $token = $guard->capture(Request::create($url));
    expect($token)->not->toBeNull();

    if ($change === 'unpublished') {
        $page->update(['visible_until' => now()->subSecond()]);
    } else {
        $page->delete();
    }

    expect(Storage::disk('page_cache')->exists($path))->toBeFalse()
        ->and($guard->publish($token, static fn (): bool => true))->toBeFalse()
        // Indexed: only the page's own URLs are withdrawn. Unindexed: its
        // dependants are unknown, so the whole cache is cleared instead.
        ->and(Storage::disk('page_cache')->exists($unrelatedPath))->toBe($indexed);
})->with(['unpublished', 'deleted'])->with(['indexed' => true, 'unindexed' => false]);

it('hard-clears origin files and fences renders when an access restriction is created or changed', function (string $change): void {
    Storage::fake('page_cache');
    config()->set('capell-html-cache.invalidation.mode', 'scheduled');
    config()->set('queue.default', 'sync');

    Schema::create('access_gate_site_access_policies', function (Blueprint $table): void {
        $table->id();
        $table->boolean('enabled')->default(false);
        $table->timestamps();
    });
    $policy = new class extends Model
    {
        protected $table = 'access_gate_site_access_policies';

        protected $guarded = [];
    };

    $domain = Model::withoutEvents(fn (): SiteDomain => SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'restricted.example.test', 'path' => null]));
    $path = resolve(HtmlCachePathResolver::class)->pathForUrl('/members', $domain);
    Storage::disk('page_cache')->put($path, 'Previously public content');

    $existing = $change === 'updated' ? Model::withoutEvents(fn (): Model => $policy->newQuery()->create(['enabled' => false])) : null;
    $guard = resolve(HtmlCachePublicationGuard::class);
    $token = $guard->capture(Request::create('https://restricted.example.test/members'));

    if ($existing instanceof Model) {
        $existing->update(['enabled' => true]);
    } else {
        $policy->newQuery()->create(['enabled' => true]);
    }

    expect(Storage::disk('page_cache')->exists($path))->toBeFalse()
        ->and($guard->publish($token, static fn (): bool => true))->toBeFalse();
})->with(['created', 'updated']);

it('hard-clears origin files and fences renders when an access area is activated, changed or deleted', function (string $change, string $mode): void {
    Storage::fake('page_cache');
    config()->set('capell-html-cache.invalidation.mode', $mode);
    config()->set('queue.default', 'sync');

    Schema::create('access_gate_areas', function (Blueprint $table): void {
        $table->id();
        $table->string('status')->default('draft');
        $table->timestamps();
    });
    $area = new class extends Model
    {
        protected $table = 'access_gate_areas';

        protected $guarded = [];
    };

    $domain = Model::withoutEvents(fn (): SiteDomain => SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'gated.example.test', 'path' => null]));
    $path = resolve(HtmlCachePathResolver::class)->pathForUrl('/members', $domain);
    Storage::disk('page_cache')->put($path, 'Previously public content');

    $existing = Model::withoutEvents(fn (): Model => $area->newQuery()->create(['status' => 'paused']));
    $guard = resolve(HtmlCachePublicationGuard::class);
    $token = $guard->capture(Request::create('https://gated.example.test/members'));

    if ($change === 'created') {
        $area->newQuery()->create(['status' => 'active']);
    } elseif ($change === 'updated') {
        $existing->update(['status' => 'active']);
    } else {
        $existing->delete();
    }

    expect(Storage::disk('page_cache')->exists($path))->toBeFalse()
        ->and($guard->publish($token, static fn (): bool => true))->toBeFalse();
})->with(['created', 'updated', 'deleted'])->with(['scheduled', 'instant']);

it('hard-clears origin files and fences renders when a site is disabled or deleted', function (string $change): void {
    Storage::fake('page_cache');
    config()->set('capell-html-cache.invalidation.mode', 'scheduled');
    config()->set('queue.default', 'sync');

    $domain = Model::withoutEvents(fn (): SiteDomain => SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'site-off.example.test', 'path' => null]));
    $path = resolve(HtmlCachePathResolver::class)->pathForUrl('/', $domain);
    Storage::disk('page_cache')->put($path, 'Previously public content');
    $guard = resolve(HtmlCachePublicationGuard::class);
    $token = $guard->capture(Request::create('https://site-off.example.test/'));

    if ($change === 'disabled') {
        $domain->site->update(['status' => false]);
    } else {
        Model::withoutEvents(fn () => $domain->forceDelete());
        $domain->site->delete();
    }

    expect(Storage::disk('page_cache')->exists($path))->toBeFalse()
        ->and($guard->publish($token, static fn (): bool => true))->toBeFalse();
})->with(['disabled', 'deleted']);

it('does not let a render in flight restore a page that is unpublished or deleted while it renders', function (string $change): void {
    Storage::fake('page_cache');
    config()->set('capell-html-cache.enabled', true);
    config()->set('capell-html-cache.write_enabled', true);
    config()->set('capell-html-cache.request_coalescing.enabled', false);
    config()->set('capell-html-cache.invalidation.mode', 'scheduled');
    config()->set('queue.default', 'sync');

    [$domain, $page] = Model::withoutEvents(function (): array {
        $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'inflight.example.test', 'path' => null]);

        return [$domain, Page::factory()->recycle($domain->site)->withTranslations()->create(['visible_from' => now()->subDay(), 'visible_until' => null])];
    });
    $path = resolve(HtmlCachePathResolver::class)->pathForUrl('/inflight', $domain);
    $request = Request::create('https://inflight.example.test/inflight');
    app()->instance('request', $request);

    $response = resolve(HtmlCacheMiddleware::class)->handle($request, function () use ($change, $page) {
        if ($change === 'unpublished') {
            $page->update(['visible_until' => now()->subSecond()]);
        } else {
            $page->delete();
        }

        return response('<main>Rendered before withdrawal</main>', 200, ['Content-Type' => 'text/html']);
    });

    expect(Storage::disk('page_cache')->exists($path))->toBeFalse()
        ->and($response->headers->get('Cache-Control'))->toContain('private', 'no-store')
        ->and($request->attributes->get(HtmlCacheMiddleware::CACHE_WRITE_SUCCEEDED_ATTRIBUTE))->toBeFalse();
})->with(['unpublished', 'deleted']);

it('still stale-marks ordinary scheduled edits that do not withdraw content', function (): void {
    Storage::fake('page_cache');
    Queue::fake();
    config()->set('capell-html-cache.invalidation.mode', 'scheduled');

    $domain = Model::withoutEvents(fn (): SiteDomain => SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'edit.example.test', 'path' => null]));
    $page = Model::withoutEvents(fn (): Page => Page::factory()->recycle($domain->site)->withTranslations()->create(['visible_from' => now()->subDay(), 'visible_until' => null, 'name' => 'Before']));
    $path = resolve(HtmlCachePathResolver::class)->pathForUrl('/edit', $domain);
    Storage::disk('page_cache')->put($path, 'Cached content');

    $page->update(['name' => 'After']);

    expect(Storage::disk('page_cache')->exists($path))->toBeTrue();
    Queue::assertNotPushed(ClearAllHtmlCacheAction::class);
});

it('removes the cached copy of the previous URL when a page URL changes', function (string $mode): void {
    Storage::fake('page_cache');
    config()->set('capell-html-cache.invalidation.mode', $mode);
    config()->set('queue.default', 'sync');

    [$domain, $pageUrl] = Model::withoutEvents(function (): array {
        $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'moved.example.test', 'path' => null]);
        $page = Page::factory()->site($domain->site)->withTranslations($domain->language)->create();

        return [$domain, PageUrl::factory()->page($page)->site($domain->site)->language($domain->language)->create(['url' => '/old-slug'])];
    });
    $resolver = resolve(HtmlCachePathResolver::class);
    $oldPath = $resolver->pathForUrl('/old-slug', $domain);
    Storage::disk('page_cache')->put($oldPath, 'Content at the previous URL');

    $pageUrl->update(['url' => '/new-slug']);

    expect(Storage::disk('page_cache')->exists($oldPath))->toBeFalse();
})->with(['scheduled', 'instant']);

/** @return array{purges: ArrayObject<int, EdgeCachePurgeData>, path: string} */
function accessAreaSweepFixture(string $mode): array
{
    Storage::fake('page_cache');
    config()->set('capell-html-cache.invalidation.mode', $mode);
    config()->set('queue.default', 'sync');
    Carbon::setTestNow('2026-11-02 09:00:00');

    /** @var ArrayObject<int, EdgeCachePurgeData> $purges */
    $purges = new ArrayObject;
    $purger = new readonly class($purges) implements CachePurger
    {
        /** @param ArrayObject<int, EdgeCachePurgeData> $purges */
        public function __construct(private ArrayObject $purges) {}

        public function purge(EdgeCachePurgeData $purge): bool
        {
            $this->purges->append($purge);

            return true;
        }
    };
    app()->instance(CachePurger::class, $purger);

    Schema::create('access_gate_areas', function (Blueprint $table): void {
        $table->id();
        $table->string('status');
        $table->timestamp('opens_at')->nullable();
        $table->timestamp('closes_at')->nullable();
        $table->timestamp('cache_withdrawn_for_opens_at')->nullable();
        $table->timestamps();
    });

    $domain = Model::withoutEvents(fn (): SiteDomain => SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'opens.example.test', 'path' => null]));

    return [
        'purges' => $purges,
        'path' => resolve(HtmlCachePathResolver::class)->pathForUrl('/members', $domain),
    ];
}

/** @param array<string, mixed> $attributes */
function insertAccessArea(array $attributes): int
{
    return DB::table('access_gate_areas')->insertGetId([...['status' => 'active', 'closes_at' => null, 'created_at' => now(), 'updated_at' => now()], ...$attributes]);
}

it('withdraws origin and edge copies once when an active access area reaches opens_at', function (string $mode): void {
    $fixture = accessAreaSweepFixture($mode);
    insertAccessArea(['opens_at' => '2026-11-02 10:00:00', 'closes_at' => '2026-11-02 12:00:00']);
    // None of these gate at 10:00:30.
    insertAccessArea(['status' => 'paused', 'opens_at' => '2026-11-02 10:00:00']);
    insertAccessArea(['opens_at' => '2026-11-02 09:30:00', 'closes_at' => '2026-11-02 10:00:10']);
    Storage::disk('page_cache')->put($fixture['path'], 'Public until the area opens');

    expect(WithdrawCachedHtmlForOpenedAccessAreasAction::run())->toBe(0)
        ->and(Storage::disk('page_cache')->exists($fixture['path']))->toBeTrue();

    $guard = resolve(HtmlCachePublicationGuard::class);
    $token = $guard->capture(Request::create('https://opens.example.test/members'));

    Carbon::setTestNow('2026-11-02 10:00:30');

    expect(WithdrawCachedHtmlForOpenedAccessAreasAction::run())->toBe(1)
        ->and(Storage::disk('page_cache')->exists($fixture['path']))->toBeFalse()
        ->and($guard->publish($token, static fn (): bool => true))->toBeFalse()
        ->and($fixture['purges'])->toHaveCount(1)
        ->and($fixture['purges']->getArrayCopy()[0]->purgeAll)->toBeTrue();

    Storage::disk('page_cache')->put($fixture['path'], 'Cached again by an ungated page');

    expect(WithdrawCachedHtmlForOpenedAccessAreasAction::run())->toBe(0)
        ->and(Storage::disk('page_cache')->exists($fixture['path']))->toBeTrue()
        ->and($fixture['purges'])->toHaveCount(1);

    Carbon::setTestNow();
})->with(['scheduled', 'instant']);

it('still withdraws once when the area is edited after opens_at but before the sweep runs', function (string $mode): void {
    $fixture = accessAreaSweepFixture($mode);
    $id = insertAccessArea(['opens_at' => '2026-11-02 10:00:00']);
    Storage::disk('page_cache')->put($fixture['path'], 'Public until the area opens');

    Carbon::setTestNow('2026-11-02 10:00:20');
    DB::table('access_gate_areas')->where('id', $id)->update(['updated_at' => now()]);

    expect(WithdrawCachedHtmlForOpenedAccessAreasAction::run())->toBe(1)
        ->and(Storage::disk('page_cache')->exists($fixture['path']))->toBeFalse()
        ->and(WithdrawCachedHtmlForOpenedAccessAreasAction::run())->toBe(0)
        ->and($fixture['purges'])->toHaveCount(1);

    Carbon::setTestNow();
})->with(['scheduled', 'instant']);

it('withdraws again at a rescheduled opens_at', function (string $mode): void {
    $fixture = accessAreaSweepFixture($mode);
    $id = insertAccessArea(['opens_at' => '2026-11-02 10:00:00']);

    Carbon::setTestNow('2026-11-02 10:00:30');
    expect(WithdrawCachedHtmlForOpenedAccessAreasAction::run())->toBe(1);

    DB::table('access_gate_areas')->where('id', $id)->update(['opens_at' => '2026-11-02 14:00:00']);
    Storage::disk('page_cache')->put($fixture['path'], 'Public again until the new opening');

    expect(WithdrawCachedHtmlForOpenedAccessAreasAction::run())->toBe(0)
        ->and(Storage::disk('page_cache')->exists($fixture['path']))->toBeTrue();

    Carbon::setTestNow('2026-11-02 14:00:30');

    expect(WithdrawCachedHtmlForOpenedAccessAreasAction::run())->toBe(1)
        ->and(Storage::disk('page_cache')->exists($fixture['path']))->toBeFalse()
        ->and(WithdrawCachedHtmlForOpenedAccessAreasAction::run())->toBe(0)
        ->and($fixture['purges'])->toHaveCount(2);

    Carbon::setTestNow();
})->with(['scheduled', 'instant']);
