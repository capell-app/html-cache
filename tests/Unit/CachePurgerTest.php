<?php

declare(strict_types=1);

use Capell\Core\Contracts\Security\ResolvesHostAddresses;
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

it('does not send purge credentials to internal addresses that PHP treats as public', function (string $endpoint): void {
    Http::fake();
    config()->set('capell-html-cache.purge.token', 'edge-token');
    config()->set('capell-html-cache.purge.endpoint', $endpoint);

    expect(resolve(HttpSurrogateKeyCachePurger::class)->purge(new EdgeCachePurgeData(tags: ['page-1'])))->toBeFalse();

    Http::assertNothingSent();
})->with([
    'carrier-grade NAT' => 'https://100.64.0.10/purge',
    'benchmarking range' => 'https://198.18.0.10/purge',
]);

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

it('resolves the purge endpoint through Core and refuses a host that resolves to an internal address', function (string $address, bool $sent): void {
    app()->instance(ResolvesHostAddresses::class, new class($address) implements ResolvesHostAddresses
    {
        public function __construct(private readonly string $address) {}

        public function resolve(string $host): array
        {
            return [$this->address];
        }
    });
    Http::fake(['https://purge.example.com/*' => Http::response(['ok' => true])]);
    config()->set('capell-html-cache.purge.token', 'edge-token');
    config()->set('capell-html-cache.purge.endpoint', 'https://purge.example.com/purge');

    expect(resolve(HttpSurrogateKeyCachePurger::class)->purge(new EdgeCachePurgeData(tags: ['page-1'])))->toBe($sent);

    $sent ? Http::assertSentCount(1) : Http::assertNothingSent();
})->with([
    'public address' => ['93.184.216.34', true],
    'rebound to loopback' => ['127.0.0.1', false],
    'rebound to metadata service' => ['169.254.169.254', false],
]);

it('refuses a purge endpoint carrying credentials or a fragment', function (string $endpoint): void {
    Http::fake();
    config()->set('capell-html-cache.purge.endpoint', $endpoint);

    expect(resolve(HttpSurrogateKeyCachePurger::class)->purge(new EdgeCachePurgeData(tags: ['page-1'])))->toBeFalse();

    Http::assertNothingSent();
})->with([
    'credentials' => 'https://user:secret@93.184.216.34/purge',
    'fragment' => 'https://93.184.216.34/purge#x',
]);

it('sends the purge to a custom endpoint port without the token reaching a refused host', function (): void {
    app()->instance(ResolvesHostAddresses::class, new class implements ResolvesHostAddresses
    {
        public function resolve(string $host): array
        {
            return $host === 'purge.example.com' ? ['93.184.216.34'] : ['10.0.0.5'];
        }
    });
    Http::fake(['*' => Http::response(['ok' => true])]);
    config()->set('capell-html-cache.purge.token', 'edge-token');

    config()->set('capell-html-cache.purge.endpoint', 'https://purge.example.com:8443/purge');
    expect(resolve(HttpSurrogateKeyCachePurger::class)->purge(new EdgeCachePurgeData(tags: ['page-1'])))->toBeTrue();

    config()->set('capell-html-cache.purge.endpoint', 'https://internal.example.com/purge');
    expect(resolve(HttpSurrogateKeyCachePurger::class)->purge(new EdgeCachePurgeData(tags: ['page-1'])))->toBeFalse();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://purge.example.com:8443/purge'
        && $request->hasHeader('Authorization', 'Bearer edge-token'));
});
