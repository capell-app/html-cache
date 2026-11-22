<?php

declare(strict_types=1);

use Capell\Core\Models\SiteDomain;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Capell\HtmlCache\Support\Cache\StatelessPaginationRequest;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Illuminate\Http\Request;

uses(HtmlCacheTestCase::class);
use Illuminate\Database\Eloquent\Model;

/** @param array<string, mixed> $attributes */
function htmlCachePathResolverSiteDomain(array $attributes): SiteDomain
{
    $siteDomain = Model::withoutEvents(static fn (): SiteDomain => new SiteDomain($attributes));

    if (! $siteDomain instanceof SiteDomain) {
        throw new RuntimeException('Expected a site domain model.');
    }

    return $siteDomain;
}

it('resolves query variants to the same files used by the page cache', function (): void {
    config()->set('capell-html-cache.enabled', true);
    config()->set('capell-html-cache.stateless_pagination.enabled', true);
    config()->set('capell-html-cache.stateless_pagination.params', ['page']);
    $domain = htmlCachePathResolverSiteDomain([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => null,
    ]);
    $url = 'https://example.test/articles?page=2';
    $suffix = StatelessPaginationRequest::cacheKeySuffix(Request::create($url));

    expect((new HtmlCachePathResolver)->pathForRequestUrl($url, $domain))
        ->toBe('https.example.test/articles' . $suffix . '.html');

    $domain->path = '/uk';
    $prefixedUrl = 'https://example.test/uk/articles?page=2';
    $prefixedSuffix = StatelessPaginationRequest::cacheKeySuffix(Request::create($prefixedUrl));

    expect((new HtmlCachePathResolver)->pathForRequestUrl($prefixedUrl, $domain))
        ->toBe('https.example.test/uk/articles' . $prefixedSuffix . '.html');
});

it('refuses to resolve unsupported query variants to canonical cache paths', function (): void {
    config()->set('capell-html-cache.enabled', true);
    config()->set('capell-html-cache.stateless_pagination.enabled', true);
    config()->set('capell-html-cache.stateless_pagination.params', ['page']);
    $domain = htmlCachePathResolverSiteDomain([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => null,
    ]);

    $resolver = new HtmlCachePathResolver;

    foreach (['preview=1', 'page=2&preview=1', 'page%5B%5D=2'] as $query) {
        expect(fn (): string => $resolver->pathForRequestUrl('https://example.test/page?' . $query, $domain))
            ->toThrow(InvalidArgumentException::class, 'Unsupported query parameters have no HTML cache path.');
    }
});

it('builds safe cache paths for domains, prefixes, errors and absolute urls', function (): void {
    $resolver = new HtmlCachePathResolver;
    $domain = htmlCachePathResolverSiteDomain([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => '/docs',
    ]);

    expect($resolver->pathForUrl('/', $domain))->toBe('https.example.test/docs.html')
        ->and($resolver->pathForUrl('/guide', $domain))->toBe('https.example.test/docs/guide.html')
        ->and($resolver->pathForUrl('/missing', $domain, error: true))->toBe('https.example.test/docs/missing.404.html')
        ->and($resolver->normalizePathFromUrl('https://example.test/docs?query=1'))->toBe('/docs')
        ->and($resolver->normalizePathFromUrl('not-a-url'))->toBe('not-a-url');
});

it('rejects unsafe cache path segments', function (): void {
    $domain = htmlCachePathResolverSiteDomain([
        'scheme' => 'https',
        'domain' => '../example.test',
        'path' => '/',
    ]);

    (new HtmlCachePathResolver)->pathForUrl('/', $domain);
})->throws(InvalidArgumentException::class);

it('rejects encoded unsafe cache path segments', function (): void {
    $domain = htmlCachePathResolverSiteDomain([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => '/',
    ]);

    $resolver = new HtmlCachePathResolver;

    foreach ([
        '/unsafe/%2e%2e/secret',
        '/unsafe/%252e%252e/secret',
        '/unsafe/%00/secret',
        '/unsafe/%5c/secret',
    ] as $url) {
        expect(fn (): string => $resolver->pathForUrl($url, $domain))
            ->toThrow(InvalidArgumentException::class);
    }
});

it('uses the resolved request origin for wildcard site domain fields', function (): void {
    $resolver = new HtmlCachePathResolver;

    foreach ([
        'host agnostic' => ['https', null],
        'scheme agnostic' => [null, 'resolved.example.test'],
        'host and scheme agnostic' => [null, null],
    ] as $scenario => [$scheme, $host]) {
        $domain = htmlCachePathResolverSiteDomain(['scheme' => $scheme, 'domain' => $host, 'path' => '/docs']);

        expect($resolver->pathForRequestUrl('https://resolved.example.test:8443/docs/guide', $domain))
            ->toBe('~port-8443/https.resolved.example.test/docs/guide.html', $scenario);
    }
});
