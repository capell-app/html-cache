<?php

declare(strict_types=1);

use Capell\Core\Actions\VisitUrlAction;
use Capell\Core\Models\Page;
use Capell\Core\Models\SiteDomain;
use Capell\HtmlCache\Actions\ClearCachedUrlAction;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Lorisleiva\Actions\Decorators\JobDecorator;
use Lorisleiva\Actions\Decorators\UniqueJobDecorator;

uses(HtmlCacheTestCase::class);

/** @return list<array{0: mixed, 1: mixed}> the url and page ID each refresh visit was queued with */
function queuedRefreshVisits(): array
{
    $queue = Queue::getFacadeRoot();

    if (! $queue instanceof QueueFake) {
        throw new RuntimeException('Fake the queue before reading queued visits.');
    }

    $visits = [];

    foreach ($queue->pushed(UniqueJobDecorator::class, static fn (JobDecorator $job): bool => $job->decorates(VisitUrlAction::class)) as $job) {
        if ($job instanceof JobDecorator) {
            $visits[] = [$job->getParameters()[0] ?? null, $job->getParameters()[1] ?? null];
        }
    }

    return $visits;
}

function recordCachedPageUrl(SiteDomain $siteDomain, Page $page, string $url, string $path): void
{
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
}

it('queues the refresh visit with the page that owns the cleared url', function (): void {
    Storage::fake('page_cache');
    Queue::fake();
    $siteDomain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $page = Page::factory()->recycle($siteDomain->site)->withTranslations()->create();
    $url = 'https://example.test/about';
    recordCachedPageUrl($siteDomain, $page, $url, '/about');

    ClearCachedUrlAction::run($url, $siteDomain, refresh: true);

    expect(queuedRefreshVisits())->toBe([[$url, $page->getKey()]]);
});

it('queues the refresh visit without a page when two pages share the cleared url', function (): void {
    Storage::fake('page_cache');
    Queue::fake();
    $siteDomain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'example.test', 'path' => null]);
    $first = Page::factory()->recycle($siteDomain->site)->withTranslations()->create();
    $second = Page::factory()->recycle($siteDomain->site)->withTranslations()->create();
    $url = 'https://example.test/shared';
    recordCachedPageUrl($siteDomain, $first, $url, '/shared');
    recordCachedPageUrl($siteDomain, $second, $url, '/shared');

    ClearCachedUrlAction::run($url, $siteDomain, refresh: true);

    expect(queuedRefreshVisits())->toBe([[$url, null]]);
});
