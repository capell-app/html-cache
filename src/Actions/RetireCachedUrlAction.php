<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Core\Actions\LoadSiteDomainFromUrlAction;
use Capell\Core\Models\SiteDomain;
use Capell\HtmlCache\Data\EdgeCachePurgeData;
use Capell\HtmlCache\Data\HtmlCacheOriginDecisionData;
use Capell\HtmlCache\Enums\HtmlCacheEligibilityReason;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Models\StaleCachedUrl;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Capell\HtmlCache\Support\Cache\StatelessPaginationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * @method static HtmlCacheEligibilityReason|null run(Request $request, Response $response, ?StaleCachedUrl $staleCachedUrl = null, bool $suppressInlineEdgePurge = false)
 */
final class RetireCachedUrlAction
{
    use AsFake;
    use AsObject;

    public function handle(Request $request, Response $response, ?StaleCachedUrl $staleCachedUrl = null, bool $suppressInlineEdgePurge = false): ?HtmlCacheEligibilityReason
    {
        $decision = HtmlCacheOriginDecisionData::forRequest($request);
        $reason = $decision?->retirementReason;
        if (! $reason instanceof HtmlCacheEligibilityReason
            || $decision?->cacheWriteSucceeded === true
            || ! $reason->isDeterministicForStaleRefresh($response->getStatusCode())
            || ! resolve(HtmlCachePathResolver::class)->hasSafeKey($request)
            || config('capell-html-cache.enabled', true) !== true
            || config('capell-html-cache.write_enabled', true) !== true
            || $request->attributes->get(HtmlCacheMiddleware::FRAGMENT_RENDER_FAILED_ATTRIBUTE) === true) {
            return null;
        }

        $suppressInlineEdgePurge = $suppressInlineEdgePurge
            || $request->attributes->get(HtmlCacheMiddleware::SUPPRESS_INLINE_EDGE_PURGE_ATTRIBUTE) === true;
        if ($staleCachedUrl instanceof StaleCachedUrl) {
            $this->evict(
                $staleCachedUrl->url,
                $staleCachedUrl->cache_path,
                $staleCachedUrl->error_cache_path,
                $staleCachedUrl->site_id,
                $staleCachedUrl->site_domain_id,
                $suppressInlineEdgePurge,
                $request,
            );
        } else {
            $resolved = LoadSiteDomainFromUrlAction::run($request->fullUrl());
            $domain = is_array($resolved) ? $resolved[0] : null;
            $paths = resolve(HtmlCachePathResolver::class);
            $this->evict(
                $request->fullUrl(),
                $paths->pathForRequestUrl($request, $domain),
                $paths->pathForRequestUrl($request, $domain, error: true),
                $domain?->site_id,
                $domain?->id,
                $suppressInlineEdgePurge,
                $request,
            );
        }

        return $reason;
    }

    public function evict(string $url, ?string $cachePath, ?string $errorCachePath, ?int $siteId, ?int $siteDomainId, bool $suppressInlineEdgePurge = false, ?Request $request = null): void
    {
        if (! resolve(HtmlCachePathResolver::class)->hasSafeKey($url)) {
            return;
        }

        if (! is_string($cachePath) || $cachePath === '' || ! is_string($errorCachePath) || $errorCachePath === '') {
            throw new RuntimeException(sprintf('Unable to resolve historical cache paths for "%s"; its cache tracking has been retained.', $url));
        }

        $domain = $siteDomainId === null ? null : SiteDomain::withTrashed()->find($siteDomainId);
        $query = CachedModelUrl::query()
            ->where('url_hash', CachedModelUrl::hashUrl($url))
            ->where('site_id', $siteId)
            ->where('site_domain_id', $siteDomainId);
        $storedPaths = Schema::hasTable((new CachedModelUrl)->getTable())
            ? array_values($query->get()->map(static fn (CachedModelUrl $row): string => $row->path)->all())
            : [];
        DeleteCachedUrlArtefactsAction::run($request ?? Request::create($url), $domain, [$cachePath, $errorCachePath], $storedPaths, includeVariants: $request === null || ! $request->headers->has(StatelessPaginationRequest::FRAGMENT_HEADER));
        // Static generator consumers can run before optional tracking migrations are installed.
        if (Schema::hasTable((new CachedModelUrl)->getTable())) {
            $query->delete();
        }
        if (! $suppressInlineEdgePurge) {
            PurgeEdgeCacheAction::dispatchAfterCommit(new EdgeCachePurgeData(urls: [$url]));
        }
    }
}
