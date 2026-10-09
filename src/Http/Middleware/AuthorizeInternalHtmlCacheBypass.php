<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class AuthorizeInternalHtmlCacheBypass
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $except = config('capell-html-cache.bypass.ignored_query_parameter_except_paths', []);
        if (($request->isMethod('GET') || $request->isMethod('HEAD')) && ! $request->is(...(is_array($except) ? $except : []))) {
            $patterns = config('capell-html-cache.bypass.ignored_query_parameters', []);
            foreach (array_keys($request->query->all()) as $key) {
                foreach (is_array($patterns) ? $patterns : [] as $pattern) {
                    if (is_string($pattern) && Str::is($pattern, (string) $key)) {
                        $request->query->remove((string) $key);
                        break;
                    }
                }
            }
        }

        $headerName = config('capell-html-cache.internal_bypass.header');
        $configuredSecret = config('capell-html-cache.internal_bypass.secret');
        $providedSecret = is_string($headerName) && $headerName !== ''
            ? $request->headers->get($headerName)
            : null;

        $isAuthorized = is_string($configuredSecret)
            && $configuredSecret !== ''
            && is_string($providedSecret)
            && hash_equals($configuredSecret, $providedSecret);

        $request->attributes->set(HtmlCacheMiddleware::INTERNAL_BYPASS_ATTRIBUTE, $isAuthorized);

        if (! $isAuthorized && is_string($headerName) && $headerName !== '') {
            $request->headers->remove($headerName);
        }

        $shellHeaderName = config('capell-html-cache.internal_shell.header');
        $shellHeaderName = is_string($shellHeaderName) ? $shellHeaderName : '';
        $shellProvidedSecret = $shellHeaderName !== ''
            ? $request->headers->get($shellHeaderName)
            : null;
        $shellAuthorized = is_string($configuredSecret)
            && $configuredSecret !== ''
            && is_string($shellProvidedSecret)
            && hash_equals($configuredSecret, $shellProvidedSecret);

        if ($shellHeaderName !== '' && $shellProvidedSecret !== null) {
            $request->attributes->set(HtmlCacheMiddleware::SHELL_VERIFICATION_ATTRIBUTE, $shellAuthorized);
        }

        if (! $shellAuthorized && $shellHeaderName !== '') {
            $request->headers->remove($shellHeaderName);
        }

        $queryParameter = config('capell-html-cache.internal_bypass.query_parameter', 'without_html_cache');
        $queryParameter = is_string($queryParameter) ? $queryParameter : '';
        $queryAllowed = ! App::environment('production') || config('capell-html-cache.internal_bypass.allow_query_in_production', false) === true;
        if ($isAuthorized || ($queryAllowed && $queryParameter !== '' && $request->query->has($queryParameter))) {
            $request->attributes->set(HtmlCacheMiddleware::BYPASS_CACHE_READ_ATTRIBUTE, true);
        }
        if (! $queryAllowed && $queryParameter !== '') {
            $request->query->remove($queryParameter);
        }

        $request->server->set('QUERY_STRING', http_build_query($request->query->all()));

        return $next($request);
    }
}
