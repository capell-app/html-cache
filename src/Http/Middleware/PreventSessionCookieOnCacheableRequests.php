<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Http\Middleware;

use Capell\HtmlCache\Support\Cache\CacheableResponseCookieStripper;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PreventSessionCookieOnCacheableRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (
            $request->isMethod('GET')
            && in_array($response->getStatusCode(), [200, 404], true)
            && $this->isPubliclyCacheableResponse($response)
        ) {
            CacheableResponseCookieStripper::strip($response);
        }

        return $response;
    }

    private function isPubliclyCacheableResponse(Response $response): bool
    {
        $cacheControl = (string) $response->headers->get('Cache-Control');

        return str_contains($cacheControl, 'public')
            || str_contains($cacheControl, 's-maxage')
            || $response->headers->get('X-Frontend-Cache') === 'HIT';
    }
}
