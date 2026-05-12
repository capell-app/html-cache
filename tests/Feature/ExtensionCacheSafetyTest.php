<?php

declare(strict_types=1);

use Capell\Frontend\Actions\Performance\RecordExtensionRenderContributionAction;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Support\Cache\PageCache;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

uses(HtmlCacheTestCase::class);

beforeEach(function (): void {
    config()->set('capell-html-cache.enabled', true);
    config()->set('capell-html-cache.write_enabled', true);
    config()->set('capell-html-cache.cache_ttl', '3600');

    app(RecordExtensionRenderContributionAction::class)->clear();
});

it('does not write public html cache for non-cacheable extension output', function (): void {
    Storage::fake('page_cache');

    $request = Request::create('https://example.test/about', Symfony\Component\HttpFoundation\Request::METHOD_GET);
    app()->instance('request', $request);
    recordHtmlCacheExtensionContribution(cacheable: false);

    $response = resolve(HtmlCacheMiddleware::class)->handle(
        $request,
        fn (): Response => response('extension html', 200, ['Content-Type' => 'text/html']),
    );

    expect(Storage::disk('page_cache')->allFiles())->toBe([])
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store');
});

it('does not write public html cache for sensitive extension output', function (): void {
    Storage::fake('page_cache');

    $request = Request::create('https://example.test/account', Symfony\Component\HttpFoundation\Request::METHOD_GET);
    app()->instance('request', $request);
    recordHtmlCacheExtensionContribution(sensitiveOutput: true);

    $response = resolve(HtmlCacheMiddleware::class)->handle(
        $request,
        fn (): Response => response('sensitive extension html', 200, ['Content-Type' => 'text/html']),
    );

    expect(Storage::disk('page_cache')->allFiles())->toBe([])
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store');
});

it('adds cacheable extension tags to the surrogate key header', function (): void {
    Storage::fake('page_cache');

    $request = Request::create('https://example.test/missing', Symfony\Component\HttpFoundation\Request::METHOD_GET);
    app()->instance('request', $request);
    recordHtmlCacheExtensionContribution(cacheTags: ['extension:editorial-tools', 'content:article']);

    $response = resolve(HtmlCacheMiddleware::class)->handle(
        $request,
        fn (): Response => response('extension missing html', 404, ['Content-Type' => 'text/html']),
    );

    $surrogateKey = (string) $response->headers->get('Surrogate-Key');

    expect($response->headers->get('X-Frontend-Cache'))->toBe('MISS')
        ->and(resolve(PageCache::class)->getCacheErrorPage($request))->toBe('extension missing html')
        ->and($surrogateKey)->toContain('extension-editorial-tools')
        ->and($surrogateKey)->toContain('content-article');
});

/**
 * @param  list<string>  $cacheTags
 */
function recordHtmlCacheExtensionContribution(
    bool $cacheable = true,
    bool $sensitiveOutput = false,
    array $cacheTags = ['extension:editorial-tools'],
): void {
    RecordExtensionRenderContributionAction::run(
        packageName: 'vendor/editorial-tools',
        surface: 'frontend',
        contributionType: 'frontend-component',
        contributionClass: 'Vendor\\EditorialTools\\Components\\RelatedStories',
        elapsedMilliseconds: 1.2,
        frontendRenderBudgetMs: 10,
        cacheTags: $cacheTags,
        cacheable: $cacheable,
        sensitiveOutput: $sensitiveOutput,
        variesBy: ['site', 'locale'],
    );
}
