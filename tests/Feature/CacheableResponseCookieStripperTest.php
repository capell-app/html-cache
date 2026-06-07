<?php

declare(strict_types=1);

use Capell\Core\Models\SiteDomain;
use Capell\Frontend\Actions\Performance\RecordExtensionRenderContributionAction;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Http\Middleware\PreventSessionCookieOnCacheableRequests;
use Capell\HtmlCache\Support\Cache\CacheableResponseCookieStripper;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Cookie;

uses(HtmlCacheTestCase::class);

beforeEach(function (): void {
    config()->set('capell-html-cache.enabled', true);
    config()->set('capell-html-cache.write_enabled', true);
    config()->set('capell-html-cache.cache_ttl', '3600');

    resolve(RecordExtensionRenderContributionAction::class)->clear();
});

it('lists the session, csrf, and debugbar cookies as a single source of truth', function (): void {
    config()->set('session.cookie', 'capell_session');

    expect(CacheableResponseCookieStripper::cookieNamesToStrip())
        ->toBe(['capell_session', 'XSRF-TOKEN', 'PHPDEBUGBAR_STACK_DATA']);
});

it('removes session, csrf, and debugbar cookies from a response', function (): void {
    config()->set('session.cookie', 'capell_session');

    $response = response('cached html', 200, ['Content-Type' => 'text/html']);
    $response->headers->setCookie(new Cookie('capell_session', 'value'));
    $response->headers->setCookie(new Cookie('XSRF-TOKEN', 'token'));
    $response->headers->setCookie(new Cookie('PHPDEBUGBAR_STACK_DATA', 'debug'));
    $response->headers->setCookie(new Cookie('keep_me', 'kept'));

    CacheableResponseCookieStripper::strip($response);

    $remainingCookieNames = array_map(
        static fn (Cookie $cookie): string => $cookie->getName(),
        $response->headers->getCookies(),
    );

    expect($remainingCookieNames)->toBe(['keep_me']);
});

it('strips the configured cookies through the prevent-session-cookie middleware path', function (): void {
    config()->set('session.cookie', 'capell_session');

    $request = Request::create('https://example.test/about', Symfony\Component\HttpFoundation\Request::METHOD_GET);

    $response = resolve(PreventSessionCookieOnCacheableRequests::class)->handle(
        $request,
        function (): Response {
            $response = response('cached html', 200, ['Content-Type' => 'text/html', 'Cache-Control' => 'public, s-maxage=600']);

            foreach (CacheableResponseCookieStripper::cookieNamesToStrip() as $cookieName) {
                $response->headers->setCookie(new Cookie($cookieName, 'stripped'));
            }

            $response->headers->setCookie(new Cookie('keep_me', 'kept'));

            return $response;
        },
    );

    $remainingCookieNames = array_map(
        static fn (Cookie $cookie): string => $cookie->getName(),
        $response->headers->getCookies(),
    );

    expect($remainingCookieNames)->toBe(['keep_me']);
});

it('strips the configured cookies through the html-cache middleware path', function (): void {
    Storage::fake('page_cache');
    config()->set('session.cookie', 'capell_session');
    SiteDomain::factory()->create([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => null,
    ]);

    $request = Request::create('https://example.test/about', Symfony\Component\HttpFoundation\Request::METHOD_GET);
    app()->instance('request', $request);

    $response = resolve(HtmlCacheMiddleware::class)->handle(
        $request,
        function (): Response {
            $response = response('fresh html', 200, ['Content-Type' => 'text/html']);

            foreach (CacheableResponseCookieStripper::cookieNamesToStrip() as $cookieName) {
                $response->headers->setCookie(new Cookie($cookieName, 'stripped'));
            }

            $response->headers->setCookie(new Cookie('keep_me', 'kept'));

            return $response;
        },
    );

    $remainingCookieNames = array_map(
        static fn (Cookie $cookie): string => $cookie->getName(),
        $response->headers->getCookies(),
    );

    expect($remainingCookieNames)->toBe(['keep_me'])
        ->and($response->headers->get('X-Frontend-Cache'))->toBe('MISS');
});
