<?php

declare(strict_types=1);

use Capell\Core\Models\SiteDomain;
use Capell\HtmlCache\Data\CacheMap\CacheMapModelSummaryData;
use Capell\HtmlCache\Data\CacheMap\CacheMapOverviewData;
use Capell\HtmlCache\Data\CacheMap\CacheMapResourceSummaryData;
use Capell\HtmlCache\Data\Dashboard\HtmlCacheDashboardStatsData;
use Capell\HtmlCache\Enums\HtmlCacheKey;
use Capell\HtmlCache\Enums\HtmlCachePermission;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;

it('keeps html cache dashboard and cache map values typed', function (): void {
    $modelSummary = new CacheMapModelSummaryData('page', 'Pages', 4, 2);
    $resourceSummary = new CacheMapResourceSummaryData('page:1', 'page', 'Pages', 1, 'Home', 2, 1);
    $overview = new CacheMapOverviewData(10, 4, [$modelSummary], [$resourceSummary]);
    $stats = new HtmlCacheDashboardStatsData(10, 8, 2, 80.0, 7, 1, 0, 87.5);

    expect($overview->modelSummaries[0]->label)->toBe('Pages')
        ->and($overview->topResources[0]->key)->toBe('page:1')
        ->and($stats->coverageRate)->toBe(80.0)
        ->and(HtmlCacheKey::GeneratingStaticSite->value)->toBe('generating-static-site')
        ->and(HtmlCachePermission::names())->toBe([
            'capell-html-cache.view',
            'capell-html-cache.clear',
            'capell-html-cache.maintenance.manage',
        ]);
});

it('builds safe cache paths for domains, prefixes, errors and absolute urls', function (): void {
    $resolver = new HtmlCachePathResolver;
    $domain = new SiteDomain([
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
    $domain = new SiteDomain([
        'scheme' => 'https',
        'domain' => '../example.test',
        'path' => '/',
    ]);

    (new HtmlCachePathResolver)->pathForUrl('/', $domain);
})->throws(InvalidArgumentException::class);
