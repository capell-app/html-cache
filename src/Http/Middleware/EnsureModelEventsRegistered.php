<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Http\Middleware;

use Capell\HtmlCache\Support\Cache\HtmlCachePublicationGuard;
use Capell\HtmlCache\Support\ModelServing\ModelEventRegistrar;
use Capell\HtmlCache\Support\ModelServing\RetrievedModelStore;
use Closure;
use Illuminate\Http\Request;

final class EnsureModelEventsRegistered
{
    public function __construct(
        private readonly ModelEventRegistrar $modelEventRegistrar,
    ) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $this->modelEventRegistrar->registerModels();

        try {
            return $next($request);
        } finally {
            $store = resolve(RetrievedModelStore::class);
            // The cache write runs after this middleware returns; it refuses a
            // render whose models were withdrawn while it ran.
            $request->attributes->set(HtmlCachePublicationGuard::RENDERED_MODELS_ATTRIBUTE, $store->modelKeys());
            $store->flushToUrl($request->url());
        }
    }
}
