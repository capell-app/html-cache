<?php

declare(strict_types=1);

use Capell\HtmlCache\Support\Cache\Purgers\HttpSurrogateKeyCachePurger;
use Capell\HtmlCache\Support\Cache\Purgers\NullCachePurger;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(HtmlCacheTestCase::class);

it('keeps the null cache purger as a successful no-op', function (): void {
    expect((new NullCachePurger)->purge(['page-1']))->toBeTrue();
});

it('sends normalized surrogate keys to the configured http purge endpoint', function (): void {
    Http::fake([
        'https://93.184.216.34/purge' => Http::response(['ok' => true]),
    ]);

    config()->set('capell-html-cache.purge.endpoint', 'https://93.184.216.34/purge');
    config()->set('capell-html-cache.purge.token', 'edge-token');
    config()->set('capell-html-cache.purge.surrogate_key_header', 'Surrogate-Key');

    $purged = resolve(HttpSurrogateKeyCachePurger::class)->purge([
        'page-1',
        'page-1',
        'Invalid Key!',
        'site-2',
    ]);

    expect($purged)->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://93.184.216.34/purge'
        && $request->hasHeader('Authorization', 'Bearer edge-token')
        && $request->hasHeader('Surrogate-Key', 'page-1 site-2')
        && $request['surrogate_keys'] === ['page-1', 'site-2']);
});

it('does not send purge credentials to private or insecure endpoints', function (): void {
    Http::fake();
    config()->set('capell-html-cache.purge.token', 'edge-token');

    config()->set('capell-html-cache.purge.endpoint', 'http://93.184.216.34/purge');
    expect(resolve(HttpSurrogateKeyCachePurger::class)->purge(['page-1']))->toBeFalse();

    config()->set('capell-html-cache.purge.endpoint', 'https://127.0.0.1/purge');
    expect(resolve(HttpSurrogateKeyCachePurger::class)->purge(['page-1']))->toBeFalse();

    Http::assertNothingSent();
});
