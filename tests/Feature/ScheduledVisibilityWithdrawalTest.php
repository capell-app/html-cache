<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\SiteDomain;
use Capell\HtmlCache\Actions\ClearAllHtmlCacheAction;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Capell\HtmlCache\Support\Cache\HtmlCachePublicationGuard;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
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

        return [$domain, $page];
    });
    $url = 'https://withdrawal.example.test/withdrawn';
    $path = resolve(HtmlCachePathResolver::class)->pathForUrl('/withdrawn', $domain);
    Storage::disk('page_cache')->put($path, 'Previously public content');
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
        ->and($guard->publish($token, static fn (): bool => true))->toBeFalse();
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
