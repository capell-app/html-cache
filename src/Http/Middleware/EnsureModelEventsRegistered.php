<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Http\Middleware;

use Capell\HtmlCache\Support\ModelServing\ModelEventRegistrar;
use Capell\HtmlCache\Support\ModelServing\RetrievedModelStore;
use Closure;
use Illuminate\Http\Request;

final class EnsureModelEventsRegistered
{
    public function handle(Request $request, Closure $next): mixed
    {
        ModelEventRegistrar::registerModels();

        try {
            return $next($request);
        } finally {
            resolve(RetrievedModelStore::class)->flushToUrl($request->url());
        }
    }
}
