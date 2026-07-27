<?php

declare(strict_types=1);

use Capell\HtmlCache\Data\EdgeCachePurgeData;
use Capell\HtmlCache\Support\Cache\Purgers\CloudflareCachePurger;
use Capell\HtmlCache\Support\Cache\Purgers\HttpSurrogateKeyCachePurger;
use Capell\HtmlCache\Support\Cache\Purgers\NullCachePurger;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(HtmlCacheTestCase::class);

it('keeps the null cache purger as a successful no-op', function (): void {
    expect((new NullCachePurger)->purge(new EdgeCachePurgeData(tags: ['page-1'])))->toBeTrue();
});

it('fails closed when edge purge is required and the null purger is resolved', function (): void {
    config()->set('capell-html-cache.purge.required', true);

    expect((new NullCachePurger)->purge(new EdgeCachePurgeData(tags: ['page-1'])))->toBeFalse();
});

it('sends normalized surrogate keys to the configured http purge endpoint', function (): void {
    Http::fake([
        'https://93.184.216.34/purge' => Http::response(['ok' => true]),
    ]);

    config()->set('capell-html-cache.purge.endpoint', 'https://93.184.216.34/purge');
    config()->set('capell-html-cache.purge.token', 'edge-token');
    config()->set('capell-html-cache.purge.surrogate_key_header', 'Surrogate-Key');

    $purged = resolve(HttpSurrogateKeyCachePurger::class)->purge(new EdgeCachePurgeData(tags: [
        'page-1',
        'page-1',
        'Invalid Key!',
        'site-2',
    ]));

    expect($purged)->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://93.184.216.34/purge'
        && $request->hasHeader('Authorization', 'Bearer edge-token')
        && $request->hasHeader('Surrogate-Key', 'page-1 site-2')
        && $request['surrogate_keys'] === ['page-1', 'site-2']
        && $request['urls'] === []
        && $request['purge_all'] === false);
});

it('does not send purge credentials to private or insecure endpoints', function (): void {
    Http::fake();
    config()->set('capell-html-cache.purge.token', 'edge-token');

    config()->set('capell-html-cache.purge.endpoint', 'http://93.184.216.34/purge');
    expect(resolve(HttpSurrogateKeyCachePurger::class)->purge(new EdgeCachePurgeData(tags: ['page-1'])))->toBeFalse();

    config()->set('capell-html-cache.purge.endpoint', 'https://127.0.0.1/purge');
    expect(resolve(HttpSurrogateKeyCachePurger::class)->purge(new EdgeCachePurgeData(tags: ['page-1'])))->toBeFalse();

    Http::assertNothingSent();
});

it('requires both precise URL and related tag purges to succeed at Cloudflare', function (): void {
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/0123456789abcdef0123456789abcdef/purge_cache' => Http::response(['success' => true]),
    ]);
    config()->set('capell-html-cache.purge.cloudflare.zone_id', '0123456789abcdef0123456789abcdef');
    config()->set('capell-html-cache.purge.token', 'cloudflare-token');

    $purged = resolve(CloudflareCachePurger::class)->purge(new EdgeCachePurgeData(
        tags: ['page-1'],
        urls: ['https://example.test/about'],
    ));

    expect($purged)->toBeTrue();

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.cloudflare.com/client/v4/zones/0123456789abcdef0123456789abcdef/purge_cache'
        && $request->hasHeader('Authorization', 'Bearer cloudflare-token')
        && $request->data() === ['files' => ['https://example.test/about']]);
    Http::assertSent(fn (Request $request): bool => $request->data() === ['tags' => ['page-1']]);
});

it('fails a combined Cloudflare purge when either required operation fails', function (): void {
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/0123456789abcdef0123456789abcdef/purge_cache' => Http::sequence()
            ->push(['success' => true])
            ->push(['success' => false], 403),
    ]);
    config()->set('capell-html-cache.purge.cloudflare.zone_id', '0123456789abcdef0123456789abcdef');
    config()->set('capell-html-cache.purge.token', 'cloudflare-token');

    expect(resolve(CloudflareCachePurger::class)->purge(new EdgeCachePurgeData(
        tags: ['page-1'],
        urls: ['https://example.test/about'],
    )))->toBeFalse();

    Http::assertSentCount(2);
});

it('supports Cloudflare tag and complete zone purges', function (): void {
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/0123456789abcdef0123456789abcdef/purge_cache' => Http::sequence()
            ->push(['success' => true])
            ->push(['success' => true]),
    ]);
    config()->set('capell-html-cache.purge.cloudflare.zone_id', '0123456789abcdef0123456789abcdef');
    config()->set('capell-html-cache.purge.token', 'cloudflare-token');
    $purger = resolve(CloudflareCachePurger::class);

    expect($purger->purge(new EdgeCachePurgeData(tags: ['site-2', 'site-2'])))->toBeTrue()
        ->and($purger->purge(new EdgeCachePurgeData(purgeAll: true)))->toBeTrue();

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->data() === ['tags' => ['site-2']]);
    Http::assertSent(fn (Request $request): bool => $request->data() === ['purge_everything' => true]);
});
