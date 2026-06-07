<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\PageUrl;
use Capell\Frontend\Actions\AssertPublicRenderContractAction;
use Capell\Frontend\Support\Context\FrontendContext;
use Capell\HtmlCache\Data\HtmlCacheEligibilityReportData;
use Capell\HtmlCache\Enums\HtmlCacheEligibilityReason;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Models\StaleCachedUrl;
use Capell\HtmlCache\Support\Cache\ConfiguredHtmlCacheBypassRules;
use Capell\HtmlCache\Support\Cache\PageCache;
use Capell\HtmlCache\Support\Extensions\ExtensionCacheSafetyResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * @method static HtmlCacheEligibilityReportData run(Request $request, ?Response $response = null, ?PageUrl $pageUrl = null)
 */
final class BuildHtmlCacheEligibilityReportAction
{
    use AsObject;

    public function handle(Request $request, ?Response $response = null, ?PageUrl $pageUrl = null): HtmlCacheEligibilityReportData
    {
        $reasons = [
            ...$this->requestReasons($request),
            ...$this->configurationReasons($response),
            ...$this->packageReasons(),
            ...$this->staleClaimReasons($request),
            ...$this->pageUrlReasons($pageUrl),
            ...($response instanceof Response ? $this->responseReasons($request, $response) : []),
        ];

        $reasons = $this->deduplicateReasons($reasons);

        $cachedUrl = $this->latestCachedUrl($request);
        $staleCachedUrl = $this->latestStaleCachedUrl($request);
        $cacheState = $this->cacheState($request, $cachedUrl, $staleCachedUrl);

        return new HtmlCacheEligibilityReportData(
            url: $request->getUri(),
            eligible: $reasons === [],
            reasons: $reasons,
            blockingPackages: resolve(ExtensionCacheSafetyResolver::class)->blockingPackageNames(),
            cacheTags: resolve(ExtensionCacheSafetyResolver::class)->cacheTags(),
            cacheState: $cacheState,
            stale: $staleCachedUrl instanceof StaleCachedUrl && $staleCachedUrl->status !== StaleCachedUrl::STATUS_PROCESSED,
            lastCachedAt: $cachedUrl?->cached_at?->toIso8601String(),
        );
    }

    /**
     * @return list<HtmlCacheEligibilityReason>
     */
    private function requestReasons(Request $request): array
    {
        $reasons = [];

        if (! $request->isMethod('GET')) {
            $reasons[] = HtmlCacheEligibilityReason::NonGetRequest;
        }

        if ($request->query->has('signature')) {
            $reasons[] = HtmlCacheEligibilityReason::SignedPreviewRequest;
        } elseif ($request->query->count() > 0) {
            $reasons[] = HtmlCacheEligibilityReason::QueryStringPresent;
        }

        if ($request->headers->has('X-Livewire')) {
            $reasons[] = HtmlCacheEligibilityReason::LivewireRequest;
        }

        if ($this->isInertiaRequest($request)) {
            $reasons[] = HtmlCacheEligibilityReason::InertiaRequest;
        }

        if ($request->headers->has('Authorization')) {
            $reasons[] = HtmlCacheEligibilityReason::AuthorizationHeaderPresent;
        }

        if (resolve(ConfiguredHtmlCacheBypassRules::class)->shouldBypass($request)) {
            $reasons[] = HtmlCacheEligibilityReason::ConfiguredBypassRule;
        }

        if (config('capell-html-cache.cache_skip_authenticated', true) === true
            && ($this->hasSessionCookie($request) || $request->user() !== null)) {
            $reasons[] = HtmlCacheEligibilityReason::AuthenticatedOrSessionRequest;
        }

        return $reasons;
    }

    /**
     * @return list<HtmlCacheEligibilityReason>
     */
    private function configurationReasons(?Response $response): array
    {
        if (config('capell-html-cache.enabled', true) !== true) {
            return [HtmlCacheEligibilityReason::CacheDisabled];
        }

        if ($response instanceof Response && config('capell-html-cache.write_enabled', true) !== true) {
            return [HtmlCacheEligibilityReason::CacheWriteDisabled];
        }

        return [];
    }

    /**
     * @return list<HtmlCacheEligibilityReason>
     */
    private function packageReasons(): array
    {
        return resolve(ExtensionCacheSafetyResolver::class)->blockingReasonCodes();
    }

    /**
     * @return list<HtmlCacheEligibilityReason>
     */
    private function staleClaimReasons(Request $request): array
    {
        $staleCachedUrlId = $request->attributes->get(HtmlCacheMiddleware::STALE_CACHE_ID_ATTRIBUTE);
        $claimToken = $request->attributes->get(HtmlCacheMiddleware::STALE_CACHE_CLAIM_TOKEN_ATTRIBUTE);

        if ($staleCachedUrlId === null && $claimToken === null) {
            return [];
        }

        if (! is_numeric($staleCachedUrlId) || ! is_string($claimToken) || $claimToken === '') {
            return [HtmlCacheEligibilityReason::StaleClaimInvalid];
        }

        $claimIsCurrent = StaleCachedUrl::query()
            ->whereKey((int) $staleCachedUrlId)
            ->where('status', StaleCachedUrl::STATUS_PROCESSING)
            ->where('claim_token', $claimToken)
            ->exists();

        return $claimIsCurrent ? [] : [HtmlCacheEligibilityReason::StaleClaimInvalid];
    }

    /**
     * @return list<HtmlCacheEligibilityReason>
     */
    private function pageUrlReasons(?PageUrl $pageUrl): array
    {
        if (! $pageUrl instanceof PageUrl) {
            return [];
        }

        $reasons = [];

        if ($pageUrl->siteDomain === null) {
            $reasons[] = HtmlCacheEligibilityReason::MissingSiteDomain;
        }

        if ($pageUrl->type === UrlTypeEnum::Redirect || $pageUrl->type === UrlTypeEnum::Redirect->value) {
            $reasons[] = HtmlCacheEligibilityReason::RedirectUrl;
        }

        if (! $pageUrl->pageable instanceof Pageable) {
            $reasons[] = HtmlCacheEligibilityReason::UnpublishedPage;
        }

        return $reasons;
    }

    /**
     * @return list<HtmlCacheEligibilityReason>
     */
    private function responseReasons(Request $request, Response $response): array
    {
        $reasons = [];

        if (! in_array($response->getStatusCode(), [Response::HTTP_OK, Response::HTTP_NOT_FOUND], true)) {
            $reasons[] = HtmlCacheEligibilityReason::UncacheableResponseStatus;
        }

        if (! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            $reasons[] = HtmlCacheEligibilityReason::NonHtmlResponse;
        }

        if (str_contains((string) $response->headers->get('Cache-Control'), 'no-store')) {
            $reasons[] = HtmlCacheEligibilityReason::ResponseNoStore;
        }

        if ($this->containsAuthoringSurface($response)) {
            $reasons[] = HtmlCacheEligibilityReason::UnsafePublicOutput;
        }

        if ($response->getStatusCode() !== Response::HTTP_NOT_FOUND && ! $this->frontendContextShouldCache()) {
            $reasons[] = HtmlCacheEligibilityReason::FrontendContextNotCacheable;
        }

        if (! resolve(PageCache::class)->shouldCachePage($request, $response) && $reasons === []) {
            $reasons[] = HtmlCacheEligibilityReason::ResponseNoStore;
        }

        return $reasons;
    }

    /**
     * @param  list<HtmlCacheEligibilityReason>  $reasons
     * @return list<HtmlCacheEligibilityReason>
     */
    private function deduplicateReasons(array $reasons): array
    {
        $seen = [];
        $deduplicated = [];

        foreach ($reasons as $reason) {
            $key = $reason->value;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $deduplicated[] = $reason;
        }

        return $deduplicated;
    }

    private function containsAuthoringSurface(Response $response): bool
    {
        if (! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            return false;
        }

        try {
            AssertPublicRenderContractAction::run($response);

            return false;
        } catch (Throwable) {
            return true;
        }
    }

    private function frontendContextShouldCache(): bool
    {
        try {
            return FrontendContext::shouldCachePage();
        } catch (Throwable) {
            return true;
        }
    }

    private function cacheState(Request $request, ?CachedModelUrl $cachedUrl, ?StaleCachedUrl $staleCachedUrl): string
    {
        try {
            $pageCache = resolve(PageCache::class);

            if ($pageCache->getCachePage($request) !== false || $pageCache->getCacheErrorPage($request) !== false) {
                return 'hit';
            }
        } catch (Throwable) {
            return 'unknown';
        }

        if ($staleCachedUrl instanceof StaleCachedUrl && $staleCachedUrl->status !== StaleCachedUrl::STATUS_PROCESSED) {
            return 'stale';
        }

        if ($cachedUrl instanceof CachedModelUrl) {
            return 'indexed';
        }

        return 'missing';
    }

    private function latestCachedUrl(Request $request): ?CachedModelUrl
    {
        if (! $this->cachedModelUrlsTableExists()) {
            return null;
        }

        return CachedModelUrl::query()
            ->where('url_hash', CachedModelUrl::hashUrl($request->getUri()))
            ->latest('cached_at')
            ->first();
    }

    private function latestStaleCachedUrl(Request $request): ?StaleCachedUrl
    {
        if (! $this->staleCachedUrlsTableExists()) {
            return null;
        }

        return StaleCachedUrl::query()
            ->where('url_hash', CachedModelUrl::hashUrl($request->getUri()))
            ->latest('updated_at')
            ->first();
    }

    private function cachedModelUrlsTableExists(): bool
    {
        try {
            return Schema::hasTable((new CachedModelUrl)->getTable());
        } catch (Throwable) {
            return false;
        }
    }

    private function staleCachedUrlsTableExists(): bool
    {
        try {
            return Schema::hasTable((new StaleCachedUrl)->getTable());
        } catch (Throwable) {
            return false;
        }
    }

    private function hasSessionCookie(Request $request): bool
    {
        $sessionCookieName = config('session.cookie');

        return is_string($sessionCookieName)
            && $sessionCookieName !== ''
            && $request->cookies->has($sessionCookieName);
    }

    private function isInertiaRequest(Request $request): bool
    {
        foreach (['X-Inertia', 'X-Inertia-Version', 'X-Inertia-Partial-Component', 'X-Inertia-Partial-Data', 'X-Inertia-Reset'] as $header) {
            if ($request->headers->has($header)) {
                return true;
            }
        }

        return false;
    }
}
