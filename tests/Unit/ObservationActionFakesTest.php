<?php

declare(strict_types=1);

use Capell\Core\Models\PageUrl;
use Capell\HtmlCache\Actions\ReadHtmlCacheRequestMetricsAction;
use Capell\HtmlCache\Actions\ResolveHtmlCachePageUrlAction;
use Capell\HtmlCache\Data\HtmlCacheRequestMetricsData;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Illuminate\Http\Request;

uses(HtmlCacheTestCase::class);

it('can replace the observation page lookup without querying site domains', function (): void {
    $request = Request::create('https://observations.test/article');
    $pageUrl = new PageUrl;
    // Mockery needs an instance mock for a final Action.
    app()->instance('LaravelActions:AsFake:' . ResolveHtmlCachePageUrlAction::class, Mockery::mock(new ResolveHtmlCachePageUrlAction));
    ResolveHtmlCachePageUrlAction::shouldRun()->once()->with($request)->andReturn($pageUrl);

    expect(ResolveHtmlCachePageUrlAction::run($request))->toBe($pageUrl);
});

it('can replace request metrics without reading the observation store', function (): void {
    $request = Request::create('https://observations.test/article');
    $pageUrl = new PageUrl;
    $metrics = new HtmlCacheRequestMetricsData(12.5, 75.0, 3, 1, '2026-10-13T00:00:00+00:00');
    app()->instance('LaravelActions:AsFake:' . ReadHtmlCacheRequestMetricsAction::class, Mockery::mock(new ReadHtmlCacheRequestMetricsAction));
    ReadHtmlCacheRequestMetricsAction::shouldRun()->once()->with($request, $pageUrl)->andReturn($metrics);

    expect(ReadHtmlCacheRequestMetricsAction::run($request, $pageUrl))->toBe($metrics);
});
