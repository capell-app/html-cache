<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Core\Actions\LoadSiteDomainFromUrlAction;
use Capell\Core\Models\SiteDomain;
use Capell\Frontend\Data\RenderHookFragmentCacheData;
use Capell\HtmlCache\Data\EdgeCachePurgeData;
use Capell\HtmlCache\Data\HtmlCacheEligibilityReportData;
use Capell\HtmlCache\Data\HtmlCacheOriginDecisionData;
use Capell\HtmlCache\Enums\HtmlCacheEligibilityReason;
use Capell\HtmlCache\Exceptions\StaleCachedUrlNotApplicableException;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Models\StaleCachedUrl;
use Capell\HtmlCache\Support\Cache\CacheableResponseCookieStripper;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Capell\HtmlCache\Support\Cache\HtmlCachePublicationGuard;
use Capell\HtmlCache\Support\Cache\PageCache;
use Capell\HtmlCache\Support\Cache\StatelessPaginationRequest;
use Capell\HtmlCache\Support\Extensions\ExtensionCacheSafetyResolver;
use Closure;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response;

/**
 * @method static void run(StaleCachedUrl $staleCachedUrl, bool $suppressInlineEdgePurge = false)
 */
final class RefreshCachedUrlAtomicallyAction
{
    use AsFake;
    use AsObject;

    public function handle(StaleCachedUrl $staleCachedUrl, bool $suppressInlineEdgePurge = false): void
    {
        // Validate before resolving the domain: missing domains can still have canonical artefacts.
        $request = $this->requestForStaleCachedUrl($staleCachedUrl);
        if ($request->query->count() > 0 && ! StatelessPaginationRequest::isCacheableVariant($request)) {
            throw new RuntimeException('Unsupported query parameters have no HTML cache path.');
        }

        $request->attributes->set(HtmlCacheMiddleware::SUPPRESS_INLINE_EDGE_PURGE_ATTRIBUTE, $suppressInlineEdgePurge);
        $resolved = LoadSiteDomainFromUrlAction::run($staleCachedUrl->url);
        $siteDomain = is_array($resolved) ? $resolved[0] : null;

        if (! $siteDomain instanceof SiteDomain) {
            if (config('capell-html-cache.enabled', true) !== true || config('capell-html-cache.write_enabled', true) !== true) {
                throw new RuntimeException('Unable to retire obsolete HTML cache while cache writes are disabled.');
            }

            $this->assertStaleCachedUrlClaimIsCurrent($staleCachedUrl);
            $this->deleteConfirmedObsoleteCache($staleCachedUrl, $suppressInlineEdgePurge);

            return;
        }

        $previousRequest = resolve('request');

        try {
            $response = $this->renderOrigin($request);

            if ($request->attributes->get(HtmlCachePublicationGuard::REJECTED_ATTRIBUTE) === true
                && ! $this->isDeclinedNotFoundWrite($request, $response)) {
                // The generation advanced while this render ran, so the page is unproven
                // rather than wrong. Render once more under a fresh token inside the same
                // claim before counting the row as a failure. A declined 404 write is not a
                // generation problem and is retired below instead of rendered again; any
                // other declined write (a failed 200 write may be transient) still gets this
                // second render.
                $request = $this->requestForStaleCachedUrl($staleCachedUrl);
                $request->attributes->set(HtmlCacheMiddleware::SUPPRESS_INLINE_EDGE_PURGE_ATTRIBUTE, $suppressInlineEdgePurge);
                $response = $this->renderOrigin($request);
            }

            if ($response->isServerError()) {
                throw new RuntimeException(sprintf('Unable to refresh stale HTML cache for "%s"; response status was %d.', $staleCachedUrl->url, $response->getStatusCode()));
            }

            $this->assertStaleCachedUrlClaimIsCurrent($staleCachedUrl);
            $this->retireRejectedNotFound($request, $response, $staleCachedUrl, $suppressInlineEdgePurge);

            $retirementReason = RetireCachedUrlAction::run($request, $response, $staleCachedUrl, $suppressInlineEdgePurge)
                ?? $this->retireUnguardedOriginResponse($request, $response, $staleCachedUrl, $suppressInlineEdgePurge);
            $rejectionReason = $retirementReason ?? $this->writeCacheFromRefreshResponse($request, $response, $staleCachedUrl, $suppressInlineEdgePurge);

            if ($rejectionReason instanceof HtmlCacheEligibilityReason) {
                $message = sprintf(
                    'Unable to refresh stale HTML cache for "%s"; response was not cacheable. Reason: %s. Status: %d. Content-Type: %s. Cache-Control: %s. Vary: %s. Cookies: %d. Query count: %d.',
                    $staleCachedUrl->url,
                    $rejectionReason->value,
                    $response->getStatusCode(),
                    (string) $response->headers->get('Content-Type'),
                    (string) $response->headers->get('Cache-Control'),
                    json_encode($response->headers->all('Vary'), JSON_THROW_ON_ERROR),
                    count($response->headers->getCookies()),
                    $request->query->count(),
                );

                if ($retirementReason instanceof HtmlCacheEligibilityReason) {
                    throw new StaleCachedUrlNotApplicableException($rejectionReason, $message);
                }

                throw new RuntimeException($message);
            }

            $this->assertStaleCachedUrlClaimIsCurrent($staleCachedUrl);
            $this->deleteAlternateStatusFile($staleCachedUrl, $response);
        } finally {
            app()->instance('request', $previousRequest);
        }
    }

    private function isDeclinedNotFoundWrite(Request $request, Response $response): bool
    {
        return $response->getStatusCode() === Response::HTTP_NOT_FOUND
            && $request->attributes->get(PageCache::WRITE_DECLINED_ATTRIBUTE) === true;
    }

    private function renderOrigin(Request $request): Response
    {
        resolve(HtmlCachePublicationGuard::class)->capture($request);
        app()->instance('request', $request);

        $kernel = resolve(HttpKernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    }

    /**
     * A 404 refresh whose cache write was declined although the response was eligible
     * cannot be repeated into success: the error page cap refuses the slot, the write
     * fails, or the middleware declines before publishing and records no reason. 404
     * statuses never carry a deterministic retirement reason, so the generic path would
     * fail the row, and the queued job, on every retry while the stale bytes kept
     * serving. Evict the stale artefacts so the next request renders live and finish
     * the row as not applicable so it stays visible.
     *
     * Anything that may still change on a retry stays retryable: a moved publication
     * generation (rejected without a declined write), a reclaimed row, a failed
     * fragment, disabled writes, and any response with a recorded ineligibility,
     * rejection or retirement reason (a non-accepted status says nothing about the
     * URL's lasting policy).
     */
    private function retireRejectedNotFound(Request $request, Response $response, StaleCachedUrl $staleCachedUrl, bool $suppressInlineEdgePurge): void
    {
        $decision = HtmlCacheOriginDecisionData::forRequest($request);
        $report = $request->attributes->get(HtmlCacheMiddleware::ELIGIBILITY_REPORT_ATTRIBUTE);
        $generationMoved = $request->attributes->get(HtmlCachePublicationGuard::REJECTED_ATTRIBUTE) === true
            && $request->attributes->get(PageCache::WRITE_DECLINED_ATTRIBUTE) !== true;

        if ($response->getStatusCode() !== Response::HTTP_NOT_FOUND
            || ! $decision instanceof HtmlCacheOriginDecisionData
            || ! $report instanceof HtmlCacheEligibilityReportData
            || ! $report->eligible
            || $decision->cacheWriteSucceeded
            || $decision->retirementReason instanceof HtmlCacheEligibilityReason
            || $decision->rejectionReason instanceof HtmlCacheEligibilityReason
            || config('capell-html-cache.enabled', true) !== true
            || config('capell-html-cache.write_enabled', true) !== true
            || $request->attributes->get(HtmlCacheMiddleware::FRAGMENT_RENDER_FAILED_ATTRIBUTE) === true
            || $generationMoved) {
            return;
        }

        $claimToken = $staleCachedUrl->claim_token;
        // Runs inside the publication lock, like the publish path: take the row lock and
        // re-check the claim before any file is removed, so a newer owner's page survives.
        $underClaim = static fn (Closure $delete): bool => DB::transaction(static function () use ($staleCachedUrl, $claimToken, $delete): bool {
            $current = StaleCachedUrl::query()
                ->whereKey($staleCachedUrl->getKey())
                ->lockForUpdate()
                ->first();

            if (! $current instanceof StaleCachedUrl
                || $current->status !== StaleCachedUrl::STATUS_PROCESSING
                || ! is_string($claimToken)
                || $current->claim_token !== $claimToken) {
                throw new RuntimeException(sprintf('Unable to retire the cached 404 for "%s"; stale row claim is no longer current.', $staleCachedUrl->url));
            }

            return $delete();
        });

        resolve(RetireCachedUrlAction::class)->evict(
            $staleCachedUrl->url,
            $staleCachedUrl->cache_path,
            $staleCachedUrl->error_cache_path,
            $staleCachedUrl->site_id,
            $staleCachedUrl->site_domain_id,
            $suppressInlineEdgePurge,
            $request,
            $underClaim,
        );

        throw new StaleCachedUrlNotApplicableException(
            HtmlCacheEligibilityReason::UncacheableResponseStatus,
            sprintf('Retired the cached 404 for "%s"; its refreshed response was not accepted for caching and no policy change was recorded, so the next request renders live.', $staleCachedUrl->url),
        );
    }

    /**
     * A route outside the cache middleware records no origin decision, so its
     * headers are provably the origin's own. Build the decision here and evict
     * through the single retirement path when it declares a lasting policy; the
     * builder already limits that to accepted statuses with a deterministic reason.
     */
    private function retireUnguardedOriginResponse(Request $request, Response $response, StaleCachedUrl $staleCachedUrl, bool $suppressInlineEdgePurge): ?HtmlCacheEligibilityReason
    {
        if (HtmlCacheOriginDecisionData::forRequest($request) instanceof HtmlCacheOriginDecisionData) {
            return null;
        }

        $reason = BuildHtmlCacheOriginDecisionAction::run($request, $response)->retirementReason;

        if (! $reason instanceof HtmlCacheEligibilityReason || ! $reason->isDeterministicForStaleRefresh($response->getStatusCode())) {
            return null;
        }

        // Only a response that is not a page may be retired here. An HTML page
        // that lost the cache middleware renders Laravel's default private
        // headers, and treating that as policy would silently uncache the site.
        if (! in_array($reason, [HtmlCacheEligibilityReason::NonHtmlResponse, HtmlCacheEligibilityReason::RedirectUrl], true)) {
            return null;
        }

        resolve(RetireCachedUrlAction::class)->evict(
            $staleCachedUrl->url,
            $staleCachedUrl->cache_path,
            $staleCachedUrl->error_cache_path,
            $staleCachedUrl->site_id,
            $staleCachedUrl->site_domain_id,
            $suppressInlineEdgePurge,
            $request,
        );

        return $reason;
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
        $hostHeader = in_array($port, [80, 443], true) ? $host : sprintf('%s:%d', $host, $port);

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

    private function writeCacheFromRefreshResponse(Request $request, Response $response, StaleCachedUrl $staleCachedUrl, bool $suppressInlineEdgePurge): ?HtmlCacheEligibilityReason
    {
        $response = CacheableResponseCookieStripper::strip($response);

        if (config('capell-html-cache.write_enabled', true) !== true) {
            return HtmlCacheEligibilityReason::CacheWriteDisabled;
        }

        if (! $this->staleRefreshClaimIsCurrent($request)) {
            return HtmlCacheEligibilityReason::StaleClaimInvalid;
        }

        if ($request->attributes->get(HtmlCacheMiddleware::FRAGMENT_RENDER_FAILED_ATTRIBUTE) === true) {
            throw new RuntimeException(sprintf('Unable to refresh stale HTML cache for "%s"; a marked render fragment failed.', $staleCachedUrl->url));
        }

        $originDecision = HtmlCacheOriginDecisionData::forRequest($request);
        if ($originDecision instanceof HtmlCacheOriginDecisionData && ! $originDecision->cacheWriteSucceeded) {
            if ($originDecision->rejectionReason instanceof HtmlCacheEligibilityReason) {
                return $originDecision->rejectionReason;
            }

            if ($request->attributes->get(PageCache::WRITE_DECLINED_ATTRIBUTE) === true) {
                throw new RuntimeException(sprintf('Unable to refresh stale HTML cache for "%s"; the cache write was refused or failed while the publication generation was unchanged; response status was %d.', $staleCachedUrl->url, $response->getStatusCode()));
            }

            if ($request->attributes->get(HtmlCachePublicationGuard::REJECTED_ATTRIBUTE) === true) {
                throw new RuntimeException(sprintf('Unable to refresh stale HTML cache for "%s"; the publication guard rejected the render twice because the cache generation advanced during each render; response status was %d.', $staleCachedUrl->url, $response->getStatusCode()));
            }

            throw new RuntimeException(sprintf('Unable to refresh stale HTML cache for "%s"; origin rendering or cache publication was rejected without a terminal cache policy change; response status was %d.', $staleCachedUrl->url, $response->getStatusCode()));
        }

        $pageCache = resolve(PageCache::class);

        if ($originDecision?->cacheWriteSucceeded === true) {
            throw_unless(
                ! $request->attributes->get(HtmlCacheMiddleware::FRAGMENT_CACHE_DATA_ATTRIBUTE) instanceof RenderHookFragmentCacheData
                || $pageCache->getCacheFragmentData(
                    $request,
                    $response->getStatusCode() === Response::HTTP_NOT_FOUND ? PageCache::ERROR_EXTENSION : '.html',
                ) instanceof RenderHookFragmentCacheData,
                RuntimeException::class,
                sprintf('Unable to refresh stale HTML cache for "%s"; fragmented cache metadata was not published.', $staleCachedUrl->url),
            );

            if (! $suppressInlineEdgePurge) {
                PurgeEdgeCacheAction::dispatchAfterCommit(new EdgeCachePurgeData(urls: [$staleCachedUrl->url]));
            }

            return null;
        }

        $packageReason = resolve(ExtensionCacheSafetyResolver::class)->blockingReasonCodes()[0] ?? null;

        if ($packageReason instanceof HtmlCacheEligibilityReason) {
            return $packageReason;
        }

        if ($response->isRedirection()) {
            return HtmlCacheEligibilityReason::RedirectUrl;
        }

        $pageCacheReason = $pageCache->rejectionReason($request, $response);

        if ($pageCacheReason instanceof HtmlCacheEligibilityReason) {
            return $pageCacheReason;
        }

        if (! WriteRefreshedHtmlCacheFileAction::run($response, $staleCachedUrl, $request)) {
            throw new RuntimeException(sprintf('Unable to refresh stale HTML cache for "%s"; content was invalidated during rendering.', $staleCachedUrl->url));
        }

        if (! $suppressInlineEdgePurge) {
            PurgeEdgeCacheAction::dispatchAfterCommit(new EdgeCachePurgeData(urls: [$staleCachedUrl->url]));
        }

        return null;
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

    private function deleteConfirmedObsoleteCache(StaleCachedUrl $staleCachedUrl, bool $suppressInlineEdgePurge): void
    {
        resolve(RetireCachedUrlAction::class)->evict(
            $staleCachedUrl->url,
            $staleCachedUrl->cache_path,
            $staleCachedUrl->error_cache_path,
            $staleCachedUrl->site_id,
            $staleCachedUrl->site_domain_id,
            $suppressInlineEdgePurge,
        );
    }

    /**
     * A status change leaves the other status's artefacts behind under historical,
     * fragment and variant keys, and the middleware prefers a surviving 200 file.
     * Evict the URL's whole scope inside one publication-guarded operation that keeps
     * the page this refresh just published, so a concurrent clear is never undone.
     */
    private function deleteAlternateStatusFile(StaleCachedUrl $staleCachedUrl, Response $response): void
    {
        $cachePath = $staleCachedUrl->cache_path;
        $errorCachePath = $staleCachedUrl->error_cache_path;

        if (! is_string($cachePath) || $cachePath === '' || ! is_string($errorCachePath) || $errorCachePath === '') {
            return;
        }

        $domain = $staleCachedUrl->site_domain_id === null ? null : SiteDomain::withTrashed()->find($staleCachedUrl->site_domain_id);
        $request = $this->requestForStaleCachedUrl($staleCachedUrl);
        // Publication writes under the URL's current key; historical rows may store an older name.
        $published = resolve(HtmlCachePathResolver::class)->pathForRequestUrl($request, $domain, error: $response->getStatusCode() === Response::HTTP_NOT_FOUND);
        $storedPaths = Schema::hasTable((new CachedModelUrl)->getTable())
            ? array_values(CachedModelUrl::query()
                ->where('url_hash', CachedModelUrl::hashUrl($staleCachedUrl->url))
                ->where('site_id', $staleCachedUrl->site_id)
                ->where('site_domain_id', $staleCachedUrl->site_domain_id)
                ->pluck('path')
                ->filter(static fn (mixed $path): bool => is_string($path) && $path !== '')
                ->all())
            : [];

        DeleteCachedUrlArtefactsAction::run(
            $request,
            $domain,
            [$cachePath, $errorCachePath],
            $storedPaths,
            includeVariants: true,
            preserve: [$published],
            // The page was published under this refresh's token; clearing stale
            // alternates that are already absent changes nothing worth fencing.
            // Fencing the URL would reject concurrent refreshes of its query
            // variants, which share the per-URL counter.
            rotateWhenUnchanged: false,
            fenceUrlWhenUnchanged: false,
        );
    }
}
