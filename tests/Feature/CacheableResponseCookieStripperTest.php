<?php

declare(strict_types=1);

use Capell\HtmlCache\Http\Middleware\PreventSessionCookieOnCacheableRequests;
use Capell\HtmlCache\Support\Cache\CacheableResponseCookieStripper;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Cookie;

uses(HtmlCacheTestCase::class);

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
            $response->headers->setCookie(new Cookie('capell_session', 'value'));
            $response->headers->setCookie(new Cookie('XSRF-TOKEN', 'token'));

            return $response;
        },
    );

    expect($response->headers->getCookies())->toBe([]);
});
