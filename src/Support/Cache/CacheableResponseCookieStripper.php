<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Cache;

use Symfony\Component\HttpFoundation\Response;

/**
 * Single source of truth for the cookies that must never be set on a
 * publicly cacheable response. Both HtmlCacheMiddleware and
 * PreventSessionCookieOnCacheableRequests delegate here so the two
 * cookie-stripping paths cannot drift apart and leak a session/CSRF cookie.
 */
final class CacheableResponseCookieStripper
{
    /**
     * @return list<string>
     */
    public static function cookieNamesToStrip(): array
    {
        $sessionCookieName = config('session.cookie');

        return array_values(array_filter([
            is_string($sessionCookieName) ? $sessionCookieName : null,
            'XSRF-TOKEN',
            'PHPDEBUGBAR_STACK_DATA',
        ], static fn (?string $cookieName): bool => is_string($cookieName) && $cookieName !== ''));
    }

    public static function strip(Response $response): Response
    {
        $cookieNamesToStrip = self::cookieNamesToStrip();

        foreach ($response->headers->getCookies() as $cookie) {
            if (in_array($cookie->getName(), $cookieNamesToStrip, true)) {
                $response->headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
            }
        }

        return $response;
    }
}
