<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Core\Actions\LoadSiteDomainFromUrlAction;
use Capell\Core\Models\SiteDomain;
use Capell\Frontend\Contracts\HtmlMinifier;
use Capell\Frontend\Support\Security\PublicHtmlSafetyInspector;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Models\StaleCachedUrl;
use Capell\HtmlCache\Support\Cache\HtmlCacheStore;
use Capell\HtmlCache\Support\Cache\PageCache;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response;

/**
 * @method static void run(StaleCachedUrl $staleCachedUrl)
 */
final class RefreshCachedUrlAtomicallyAction
{
    use AsObject;

    public function handle(StaleCachedUrl $staleCachedUrl): void
    {
        $resolved = LoadSiteDomainFromUrlAction::run($staleCachedUrl->url);
        $siteDomain = is_array($resolved) && ($resolved[0] ?? null) instanceof SiteDomain ? $resolved[0] : null;

        if (! $siteDomain instanceof SiteDomain) {
            $this->deleteConfirmedObsoleteCache($staleCachedUrl);

            return;
        }

        $request = $this->requestForUrl($staleCachedUrl->url);
        $previousRequest = app('request');
        app()->instance('request', $request);

        try {
            $kernel = resolve(HttpKernel::class);
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);

            if ($response->isServerError()) {
                throw new RuntimeException(sprintf('Unable to refresh stale HTML cache for "%s"; response status was %d.', $staleCachedUrl->url, $response->getStatusCode()));
            }

            $pageCache = resolve(PageCache::class);

            if (! $this->shouldCacheRefreshResponse($request, $response)) {
                throw new RuntimeException(sprintf(
                    'Unable to refresh stale HTML cache for "%s"; response was not cacheable. Status: %d. Content-Type: %s. Query count: %d.',
                    $staleCachedUrl->url,
                    $response->getStatusCode(),
                    (string) $response->headers->get('Content-Type'),
                    $request->query->count(),
                ));
            }

            $pageCache->cache($request, $response);
            $this->writeRefreshedCacheFile($staleCachedUrl, $response);
            $this->deleteAlternateStatusFile($staleCachedUrl, $response);
        } finally {
            app()->instance('request', $previousRequest);
        }
    }

    private function requestForUrl(string $url): Request
    {
        $components = parse_url($url);
        $host = $components['host'] ?? null;
        $path = $components['path'] ?? '/';
        $query = $components['query'] ?? null;
        $scheme = $components['scheme'] ?? 'https';

        if (! is_string($host) || $host === '' || ! is_string($path)) {
            throw new RuntimeException(sprintf('Unable to refresh stale HTML cache for invalid URL "%s".', $url));
        }

        $uri = $query === null ? $path : $path . '?' . $query;
        $port = $components['port'] ?? ($scheme === 'http' ? 80 : 443);
        $hostHeader = $port === 80 || $port === 443 ? $host : sprintf('%s:%d', $host, $port);

        $request = Request::create($uri, SymfonyRequest::METHOD_GET, server: [
            'HTTP_HOST' => $hostHeader,
            'SERVER_NAME' => $host,
            'SERVER_PORT' => $port,
            'HTTPS' => $scheme === 'https' ? 'on' : 'off',
        ]);
        $request->attributes->set(HtmlCacheMiddleware::BYPASS_CACHE_READ_ATTRIBUTE, true);

        return $request;
    }

    private function shouldCacheRefreshResponse(Request $request, Response $response): bool
    {
        if (! $request->isMethod(SymfonyRequest::METHOD_GET)) {
            return false;
        }

        if ($request->query->count() > 0) {
            return false;
        }

        if (! in_array($response->getStatusCode(), [Response::HTTP_OK, Response::HTTP_NOT_FOUND], true)) {
            return false;
        }

        if (mb_strpos((string) $response->headers->get('Content-Type'), 'text/html') === false) {
            return false;
        }

        return ! resolve(PublicHtmlSafetyInspector::class)->containsAuthoringSurface((string) $response->getContent());
    }

    private function writeRefreshedCacheFile(StaleCachedUrl $staleCachedUrl, Response $response): void
    {
        if ($response->getStatusCode() !== Response::HTTP_OK) {
            return;
        }

        if (! is_string($staleCachedUrl->cache_path) || $staleCachedUrl->cache_path === '') {
            return;
        }

        $content = (string) $response->getContent();

        if (config('capell-html-cache.minify_html', true) === true) {
            $content = resolve(HtmlMinifier::class)->minify($content);
        }

        resolve(HtmlCacheStore::class)->replace($staleCachedUrl->cache_path, $content);
    }

    private function deleteConfirmedObsoleteCache(StaleCachedUrl $staleCachedUrl): void
    {
        $store = resolve(HtmlCacheStore::class);

        if (is_string($staleCachedUrl->cache_path) && $staleCachedUrl->cache_path !== '') {
            $store->delete($staleCachedUrl->cache_path);
        }

        if (is_string($staleCachedUrl->error_cache_path) && $staleCachedUrl->error_cache_path !== '') {
            $store->delete($staleCachedUrl->error_cache_path);
        }

        $query = CachedModelUrl::query()->where('url_hash', $staleCachedUrl->url_hash);

        if ($staleCachedUrl->site_id !== null) {
            $query->where('site_id', $staleCachedUrl->site_id);
        }

        if ($staleCachedUrl->site_domain_id !== null) {
            $query->where('site_domain_id', $staleCachedUrl->site_domain_id);
        }

        $query->delete();
    }

    private function deleteAlternateStatusFile(StaleCachedUrl $staleCachedUrl, Response $response): void
    {
        $store = resolve(HtmlCacheStore::class);

        if ($response->getStatusCode() === Response::HTTP_NOT_FOUND) {
            if (is_string($staleCachedUrl->cache_path) && $staleCachedUrl->cache_path !== '') {
                $store->delete($staleCachedUrl->cache_path);
            }

            return;
        }

        if (is_string($staleCachedUrl->error_cache_path) && $staleCachedUrl->error_cache_path !== '') {
            $store->delete($staleCachedUrl->error_cache_path);
        }
    }
}
