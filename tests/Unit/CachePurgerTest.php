<?php

declare(strict_types=1);

use Capell\HtmlCache\Support\Cache\Purgers\HttpSurrogateKeyCachePurger;
use Capell\HtmlCache\Support\Cache\Purgers\NullCachePurger;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('keeps the null cache purger as a successful no-op', function (): void {
    expect((new NullCachePurger)->purge(['page-1']))->toBeTrue();
});

it('sends normalized surrogate keys to the configured http purge endpoint', function (): void {
    Http::fake([
        'https://cache.example.test/purge' => Http::response(['ok' => true]),
    ]);

    config()->set('capell-html-cache.purge.endpoint', 'https://cache.example.test/purge');
    config()->set('capell-html-cache.purge.token', 'edge-token');
    config()->set('capell-html-cache.purge.surrogate_key_header', 'Surrogate-Key');

    $purged = resolve(HttpSurrogateKeyCachePurger::class)->purge([
        'page-1',
        'page-1',
        'Invalid Key!',
        'site-2',
    ]);

    expect($purged)->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://cache.example.test/purge'
        && $request->hasHeader('Authorization', 'Bearer edge-token')
        && $request->hasHeader('Surrogate-Key', 'page-1 site-2')
        && $request['surrogate_keys'] === ['page-1', 'site-2']);
});
