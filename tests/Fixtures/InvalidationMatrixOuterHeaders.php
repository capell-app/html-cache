<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Tests\Fixtures;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class InvalidationMatrixOuterHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }
}
