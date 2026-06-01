<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Core\Actions\LoadSiteDomainFromUrlAction;
use Capell\Core\Models\SiteDomain;
use Capell\Frontend\Contracts\CacheBypassResolver;
use Capell\Frontend\Contracts\HtmlMinifier;
use Capell\Frontend\Support\Context\FrontendContext;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Models\StaleCachedUrl;
use Capell\HtmlCache\Support\Cache\HtmlCacheStore;
use Capell\HtmlCache\Support\Cache\PageCache;
use Capell\HtmlCache\Support\Extensions\ExtensionCacheSafetyResolver;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

        $this->writeCacheFile($response, $staleCachedUrl);

        return true;
    }

    private function writeCacheFile(Response $response, StaleCachedUrl $staleCachedUrl): void
    {
        $cachePath = $response->getStatusCode() === Response::HTTP_NOT_FOUND
            ? $staleCachedUrl->error_cache_path
            : $staleCachedUrl->cache_path;

        if (! is_string($cachePath) || $cachePath === '') {
            throw new RuntimeException(sprintf('Unable to refresh stale HTML cache for "%s"; stale row cache path was missing.', $staleCachedUrl->url));
        }

        $content = (string) $response->getContent();

        if ($response->getStatusCode() !== Response::HTTP_NOT_FOUND && config('capell-html-cache.minify_html', true) === true) {
            $content = resolve(HtmlMinifier::class)->minify($content);
        }

        $safeCachePath = $this->safeCachePath($cachePath);
        $disk = Storage::disk('page_cache');
        $path = $disk->path($safeCachePath);
        $root = rtrim(str_replace('\\', '/', $disk->path('')), '/');
        $normalizedPath = str_replace('\\', '/', $path);

        if ($normalizedPath !== $root && ! str_starts_with($normalizedPath, $root . '/')) {
            throw new RuntimeException(sprintf('Unable to refresh stale HTML cache for "%s"; stale row cache path was outside the cache disk.', $staleCachedUrl->url));
        }

        File::ensureDirectoryExists(dirname($path), 0775, true);
        $this->replaceCacheFileForCurrentStaleClaim($staleCachedUrl, $path, $content);
    }

    private function safeCachePath(string $cachePath): string
    {
        $normalized = str_replace('\\', '/', $cachePath);

        throw_if($normalized === '' || str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:\//', $normalized) === 1 || str_contains($normalized, "\0"), RuntimeException::class, 'Unable to refresh stale HTML cache; stale row cache path was invalid.');

        $segments = array_values(array_filter(explode('/', $normalized), static fn (string $segment): bool => $segment !== ''));

        foreach ($segments as $segment) {
            throw_if($segment === '..', RuntimeException::class, 'Unable to refresh stale HTML cache; stale row cache path was invalid.');
        }

        return implode('/', $segments);
    }

    private function replaceCacheFileForCurrentStaleClaim(StaleCachedUrl $staleCachedUrl, string $path, string $content): void
    {
        $claimToken = $staleCachedUrl->claim_token;

        if (! is_string($claimToken) || $claimToken === '') {
            throw new RuntimeException(sprintf('Unable to refresh stale HTML cache for "%s"; stale row claim was missing.', $staleCachedUrl->url));
        }

        $temporaryPath = $this->temporaryPathForAtomicReplace($path);
        $bytesWritten = File::put($temporaryPath, $content);

        if ($bytesWritten === false || $bytesWritten !== strlen($content)) {
            throw new RuntimeException(sprintf('Unable to write temporary cache file for "%s".', $path));
        }

        try {
            DB::transaction(function () use ($staleCachedUrl, $claimToken, $path, $temporaryPath): void {
                $currentStaleCachedUrl = StaleCachedUrl::query()
                    ->whereKey($staleCachedUrl->getKey())
                    ->lockForUpdate()
                    ->first();

                if (
                    ! $currentStaleCachedUrl instanceof StaleCachedUrl
                    || $currentStaleCachedUrl->status !== StaleCachedUrl::STATUS_PROCESSING
                    || $currentStaleCachedUrl->claim_token !== $claimToken
                ) {
                    return;
                }

                if (! File::move($temporaryPath, $path)) {
                    throw new RuntimeException(sprintf('Unable to replace cache file for "%s".', $path));
                }
            });
        } finally {
            if (File::exists($temporaryPath)) {
                File::delete($temporaryPath);
            }
        }
    }

    private function temporaryPathForAtomicReplace(string $path): string
    {
        return dirname($path) . DIRECTORY_SEPARATOR . basename($path) . '.tmp.' . Str::uuid()->toString();
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
