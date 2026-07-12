<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Core\Actions\LoadSiteDomainFromUrlAction;
use Capell\Core\Models\SiteDomain;
use Capell\Frontend\Contracts\CacheBypassResolver;
use Capell\Frontend\Support\Context\FrontendContext;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Models\StaleCachedUrl;
use Capell\HtmlCache\Support\Cache\HtmlCacheStore;
use Capell\HtmlCache\Support\Cache\PageCache;
use Capell\HtmlCache\Support\Extensions\ExtensionCacheSafetyResolver;
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
            $this->assertStaleCachedUrlClaimIsCurrent($staleCachedUrl);
            $this->deleteConfirmedObsoleteCache($staleCachedUrl);

            return;
        }

        $request = $this->requestForStaleCachedUrl($staleCachedUrl);
        $previousRequest = resolve('request');
        app()->instance('request', $request);

        try {
            $kernel = resolve(HttpKernel::class);
            $htmlCacheEnabled = config('capell-html-cache.enabled', true);
            $previousCacheBypassResolver = resolve(CacheBypassResolver::class);

            config()->set('capell-html-cache.enabled', false);
            app()->instance(CacheBypassResolver::class, new class implements CacheBypassResolver
            {
                public function shouldBypass(): bool
                {
                    return true;
                }
            });

            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);

            config()->set('capell-html-cache.enabled', $htmlCacheEnabled);
            app()->instance(CacheBypassResolver::class, $previousCacheBypassResolver);

            if ($response->isServerError()) {
                throw new RuntimeException(sprintf('Unable to refresh stale HTML cache for "%s"; response status was %d.', $staleCachedUrl->url, $response->getStatusCode()));
            }

            if (! $this->writeCacheFromRefreshResponse($request, $response, $staleCachedUrl)) {
                throw new RuntimeException(sprintf(
                    'Unable to refresh stale HTML cache for "%s"; response was not cacheable. Status: %d. Content-Type: %s. Query count: %d.',
                    $staleCachedUrl->url,
                    $response->getStatusCode(),
                    (string) $response->headers->get('Content-Type'),
                    $request->query->count(),
                ));
            }

            $this->assertStaleCachedUrlClaimIsCurrent($staleCachedUrl);
            $this->deleteAlternateStatusFile($staleCachedUrl, $response);
        } finally {
            if (isset($htmlCacheEnabled)) {
                config()->set('capell-html-cache.enabled', $htmlCacheEnabled);
            }

            if (isset($previousCacheBypassResolver)) {
                app()->instance(CacheBypassResolver::class, $previousCacheBypassResolver);
            }

            app()->instance('request', $previousRequest);
        }
    }

    private function requestForStaleCachedUrl(StaleCachedUrl $staleCachedUrl): Request
    {
        $url = $staleCachedUrl->url;
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
        $request->attributes->set(HtmlCacheMiddleware::STALE_CACHE_ID_ATTRIBUTE, $staleCachedUrl->getKey());
        $request->attributes->set(HtmlCacheMiddleware::STALE_CACHE_CLAIM_TOKEN_ATTRIBUTE, $staleCachedUrl->claim_token);
        $request->attributes->set(HtmlCacheMiddleware::SYNTHETIC_RENDER_ATTRIBUTE, true);

        return $request;
    }

    private function writeCacheFromRefreshResponse(Request $request, Response $response, StaleCachedUrl $staleCachedUrl): bool
    {
        if (config('capell-html-cache.write_enabled', true) !== true) {
            return false;
        }

        if (! $this->staleRefreshClaimIsCurrent($request)) {
            return false;
        }

        if (! resolve(ExtensionCacheSafetyResolver::class)->isPublicCacheSafe()) {
            return false;
        }

        $pageCache = resolve(PageCache::class);

        if (! $pageCache->shouldCachePage($request, $response)) {
            return false;
        }

        if ($response->getStatusCode() !== Response::HTTP_NOT_FOUND && ! FrontendContext::shouldCachePage()) {
            return false;
        }

        WriteRefreshedHtmlCacheFileAction::run($response, $staleCachedUrl);

        return true;
    }

    private function staleRefreshClaimIsCurrent(Request $request): bool
    {
        $staleCachedUrlId = $request->attributes->get(HtmlCacheMiddleware::STALE_CACHE_ID_ATTRIBUTE);
        $claimToken = $request->attributes->get(HtmlCacheMiddleware::STALE_CACHE_CLAIM_TOKEN_ATTRIBUTE);

        if (! is_numeric($staleCachedUrlId) || ! is_string($claimToken) || $claimToken === '') {
            return false;
        }

        return StaleCachedUrl::query()
            ->whereKey((int) $staleCachedUrlId)
            ->where('status', StaleCachedUrl::STATUS_PROCESSING)
            ->where('claim_token', $claimToken)
            ->exists();
    }

    private function assertStaleCachedUrlClaimIsCurrent(StaleCachedUrl $staleCachedUrl): void
    {
        $claimToken = $staleCachedUrl->claim_token;

        if (! is_string($claimToken) || $claimToken === '') {
            throw new RuntimeException(sprintf('Unable to refresh stale HTML cache for "%s"; stale row claim was missing.', $staleCachedUrl->url));
        }

        $claimIsCurrent = StaleCachedUrl::query()
            ->whereKey($staleCachedUrl->getKey())
            ->where('status', StaleCachedUrl::STATUS_PROCESSING)
            ->where('claim_token', $claimToken)
            ->exists();

        if (! $claimIsCurrent) {
            throw new RuntimeException(sprintf('Unable to refresh stale HTML cache for "%s"; stale row claim is no longer current.', $staleCachedUrl->url));
        }
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
