<?php

declare(strict_types=1);

use Capell\HtmlCache\Http\Middleware\AuthorizeInternalHtmlCacheBypass;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Support\Cache\ConfiguredHtmlCacheBypassRules;
use Capell\HtmlCache\Support\Cache\PageCache;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

uses(HtmlCacheTestCase::class);

it('authenticates internal bypass and shell headers while denying public production query bypass', function (): void {
    app()->instance('env', 'production');
    config(['capell-html-cache.internal_bypass.header' => 'X-Test-Bypass', 'capell-html-cache.internal_bypass.secret' => 'secret', 'capell-html-cache.internal_shell.header' => 'X-Test-Shell']);
    $request = Request::create('/?without_html_cache=1');
    $request->headers->set('X-Test-Bypass', 'wrong');
    $request->headers->set('X-Test-Shell', 'wrong');
    (new AuthorizeInternalHtmlCacheBypass)->handle($request, fn () => response('ok'));
    expect($request->query->has('without_html_cache'))->toBeFalse()->and($request->headers->has('X-Test-Bypass'))->toBeFalse()->and($request->attributes->get(HtmlCacheMiddleware::SHELL_VERIFICATION_ATTRIBUTE))->toBeFalse();
    $request->headers->set('X-Test-Bypass', 'secret');
    $request->headers->set('X-Test-Shell', 'secret');
    (new AuthorizeInternalHtmlCacheBypass)->handle($request, fn () => response('ok'));
    expect($request->attributes->get(HtmlCacheMiddleware::BYPASS_CACHE_READ_ATTRIBUTE))->toBeTrue()->and($request->attributes->get(HtmlCacheMiddleware::SHELL_VERIFICATION_ATTRIBUTE))->toBeTrue();
});

it('retains local query bypass and only strips configured tracking parameters for cache eligibility', function (): void {
    config(['capell-html-cache.bypass.ignored_query_parameters' => ['utm_*', 'gclid']]);
    $request = Request::create('/?utm_source=test&gclid=123&meaningful=1&without_html_cache=1');
    (new AuthorizeInternalHtmlCacheBypass)->handle($request, fn () => response('ok'));
    expect($request->query->all())->toHaveKeys(['meaningful', 'without_html_cache'])->not->toHaveKey('utm_source');
    expect($request->attributes->get(HtmlCacheMiddleware::BYPASS_CACHE_READ_ATTRIBUTE))->toBeTrue();
});

it('applies authentication and normalisation to explicit frontend cache routes', function (): void {
    app()->instance('env', 'production');
    config(['capell-html-cache.enabled' => false, 'capell-html-cache.internal_bypass.header' => 'X-Test-Bypass', 'capell-html-cache.internal_bypass.secret' => 'secret', 'capell-html-cache.bypass.ignored_query_parameters' => ['utm_*']]);
    Route::get('/cache-bypass-proof', fn (Request $request) => response()->json(['bypass' => $request->attributes->get(HtmlCacheMiddleware::BYPASS_CACHE_READ_ATTRIBUTE) === true, 'query' => $request->query->all(), 'query_string' => $request->server->get('QUERY_STRING')]))->middleware('frontend.cache');
    $this->get('/cache-bypass-proof?utm_source=campaign&without_html_cache=1')->assertOk()->assertJson(['bypass' => false, 'query' => [], 'query_string' => '']);
    $this->get('/cache-bypass-proof', ['X-Test-Bypass' => 'secret'])->assertOk()->assertJson(['bypass' => true])->assertHeader('Cache-Control', 'no-store, private');
});

it('preserves acquisition query data and rejects empty authentication secrets', function (): void {
    app()->instance('env', 'production');
    config(['capell-html-cache.internal_bypass.header' => 'X-Test-Bypass', 'capell-html-cache.internal_bypass.secret' => '', 'capell-html-cache.bypass.ignored_query_parameters' => ['utm_*'], 'capell-html-cache.bypass.ignored_query_parameter_except_paths' => ['acquisition']]);
    $request = Request::create('/acquisition?utm_source=campaign');
    $request->headers->set('X-Test-Bypass', '');
    (new AuthorizeInternalHtmlCacheBypass)->handle($request, fn () => response('ok'));
    expect($request->query->get('utm_source'))->toBe('campaign')->and($request->getQueryString())->toBe('utm_source=campaign')->and($request->attributes->get(HtmlCacheMiddleware::BYPASS_CACHE_READ_ATTRIBUTE))->not->toBeTrue();
});

it('keeps authenticated internal requests out of both cache reads and writes without changing refresh bypasses', function (): void {
    app()->instance('env', 'production');
    config(['capell-html-cache.enabled' => true, 'capell-html-cache.write_enabled' => true, 'capell-html-cache.bypass.headers' => [], 'capell-html-cache.internal_bypass.header' => 'X-Test-Bypass', 'capell-html-cache.internal_bypass.secret' => 'secret']);
    app()->bind(PageCache::class, function (): PageCache {
        throw new LogicException('Internal verification must not access the cache.');
    });
    Route::get('/internal-cache-proof', fn () => response('<main>Uncached origin HTML</main>'))->middleware('frontend.cache');
    $this->get('/internal-cache-proof', ['X-Test-Bypass' => 'secret'])->assertOk()->assertContent('<main>Uncached origin HTML</main>')->assertHeader('Cache-Control', 'no-store, private');
    $refresh = Request::create('/internal-cache-proof');
    $refresh->attributes->set(HtmlCacheMiddleware::BYPASS_CACHE_READ_ATTRIBUTE, true);
    expect(resolve(ConfiguredHtmlCacheBypassRules::class)->shouldBypass($refresh))->toBeFalse();
});
