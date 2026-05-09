<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PreventSessionCookieOnCacheableRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->isMethod('GET') && in_array($response->getStatusCode(), [200, 404], true)) {
            $this->stripSessionCookies($response);
        }

        return $response;
    }

    private function stripSessionCookies(Response $response): void
    {
        $cookiesToRemove = [
            config('session.cookie'),
            'XSRF-TOKEN',
            'PHPDEBUGBAR_STACK_DATA',
        ];

        foreach ($response->headers->getCookies() as $cookie) {
            if (in_array($cookie->getName(), $cookiesToRemove, true)) {
                $response->headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
            }
        }
    }
}
