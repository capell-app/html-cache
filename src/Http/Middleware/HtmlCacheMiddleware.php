<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Http\Middleware;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Frontend\Actions\AssertPublicHtmlContainsNoAuthoringSurfaceAction;
use Capell\Frontend\Contracts\CacheBypassResolver;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Contracts\HtmlMinifier;
use Capell\Frontend\Data\RenderHookFragmentCacheData;
use Capell\Frontend\Support\Cache\SurrogateKeyNormalizer;
use Capell\Frontend\Support\Render\RenderHookFragmentRegistry;
use Capell\Frontend\Support\Render\RenderHookRegistry;
use Capell\Frontend\Support\Security\PublicHtmlSafetyInspector;
use Capell\HtmlCache\Actions\BuildHtmlCacheEligibilityReportAction;
use Capell\HtmlCache\Actions\RecordHtmlCacheHitAction;
use Capell\HtmlCache\Actions\RenderCoalescedHtmlCacheMissAction;
use Capell\HtmlCache\Actions\ResolveEdgeCacheTagsAction;
use Capell\HtmlCache\Actions\ScheduleOriginStaleCachedUrlRefreshAction;
use Capell\HtmlCache\Data\HtmlCacheEligibilityReportData;
use Capell\HtmlCache\Enums\HtmlCacheEligibilityReason;
use Capell\HtmlCache\Enums\HtmlCacheRenderLockStatus;
use Capell\HtmlCache\Support\AccessGate\ActiveAccessGateAreaResolver;
use Capell\HtmlCache\Support\Cache\CacheableResponseCookieStripper;
use Capell\HtmlCache\Support\Cache\ConfiguredHtmlCacheBypassRules;
use Capell\HtmlCache\Support\Cache\HtmlCachePublicationGuard;
use Capell\HtmlCache\Support\Cache\PageCache;
use Capell\HtmlCache\Support\Cache\PublicResponseCachePolicy;
use Capell\HtmlCache\Support\Cache\StatelessPaginationRequest;
use Capell\HtmlCache\Support\Extensions\ExtensionCacheSafetyResolver;
use Closure;
use Exception;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class HtmlCacheMiddleware
{
    public const string BYPASS_CACHE_READ_ATTRIBUTE = 'capell.html_cache.bypass_cache_read';

    public const string CACHE_WRITE_SUCCEEDED_ATTRIBUTE = 'capell.html_cache.cache_write_succeeded';

    public const string STALE_CACHE_ID_ATTRIBUTE = 'capell.html_cache.stale_cache_id';

    public const string STALE_CACHE_CLAIM_TOKEN_ATTRIBUTE = 'capell.html_cache.stale_cache_claim_token';

    public const string SYNTHETIC_RENDER_ATTRIBUTE = 'capell.html_cache.synthetic_render';

    public const string ELIGIBILITY_REPORT_ATTRIBUTE = 'capell.html_cache.eligibility_report';

    public const string FRAGMENT_CACHE_DATA_ATTRIBUTE = 'capell.html_cache.fragment_cache_data';

    public const string FRAGMENT_RENDER_FAILED_ATTRIBUTE = 'capell.html_cache.fragment_render_failed';

    public const string PRIVATE_RESPONSE_ATTRIBUTE = 'capell.html_cache.private_response';

    public const string SHELL_VERIFICATION_ATTRIBUTE = 'capell.html_cache.shell_verification';

    private const string INCOMING_SESSION_COOKIE_ATTRIBUTE = 'capell.html_cache.incoming_session_cookie';

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(self::INCOMING_SESSION_COOKIE_ATTRIBUTE, $this->hasSessionCookie($request));

        if (resolve(ConfiguredHtmlCacheBypassRules::class)->shouldBypass($request)) {
            return $this->privateNoStore($next($request), $request);
        }

        if ($this->shouldBypassForAccessGate($request)) {
            return $this->privateNoStore($next($request), $request);
        }

        if (resolve(CacheBypassResolver::class)->shouldBypass()) {
            return $next($request);
        }

        if (config('capell-html-cache.enabled', true) !== true) {
            $response = $next($request);

            if ($request->attributes->get(self::SYNTHETIC_RENDER_ATTRIBUTE) === true) {
                return $response;
            }

            return $this->applyCacheHeaders($request, $response);
        }

        $forceCacheReadBypass = $this->shouldForceCacheReadBypass($request);

        if (! $forceCacheReadBypass && $this->shouldBypassCacheRead($request)) {
            $response = $next($request);
            $request->attributes->set(
                self::ELIGIBILITY_REPORT_ATTRIBUTE,
                BuildHtmlCacheEligibilityReportAction::run($request, $response),
            );

            if ($this->shouldBypassHttpCache($request, $response)) {
                return $this->privateNoStore($response, $request);
            }

            return $this->applyCacheHeaders($request, $response);
        }

        $pageCache = resolve(PageCache::class);

        if (! $forceCacheReadBypass) {
            $cachedPage = $pageCache->getCachePage($request);

            if (is_string($cachedPage)) {
                $response = $this->cachedPageResponse($pageCache, $request, $cachedPage, Response::HTTP_OK);
                RecordHtmlCacheHitAction::run($request, strlen((string) $response->getContent()));
                ScheduleOriginStaleCachedUrlRefreshAction::run($request->fullUrl());

                return $response;
            }

            $cachedErrorPage = $pageCache->getCacheErrorPage($request);

            if (is_string($cachedErrorPage)) {
                RecordHtmlCacheHitAction::run($request, strlen($cachedErrorPage));
                ScheduleOriginStaleCachedUrlRefreshAction::run($request->fullUrl());

                return $this->cachedPageResponse($pageCache, $request, $cachedErrorPage, 404, PageCache::ERROR_EXTENSION);
            }
        }

        if (! $forceCacheReadBypass && $this->shouldCoalesce($request)) {
            $lock = Cache::lock(
                'capell-html-cache:render:' . hash('sha256', $request->fullUrl()),
                $this->positiveConfigInteger('capell-html-cache.request_coalescing.lock_seconds', 15),
            );
            $coalescedResponse = HtmlCacheRenderLockStatus::Contended;
            $coalescingRequired = true;

            try {
                $lock->block($this->positiveConfigInteger('capell-html-cache.request_coalescing.wait_seconds', 3));

                try {
                    // The owner may have marked this URL uncacheable while we waited.
                    $coalescingRequired = $this->shouldCoalesce($request);

                    if ($coalescingRequired) {
                        $coalescedResponse = RenderCoalescedHtmlCacheMissAction::run(
                            $request,
                            fn (): Response => $this->handleCoalescedCacheMiss($pageCache, $request, $next),
                        );
                    }
                } finally {
                    $lock->release();
                }
            } catch (LockTimeoutException) {
                // Recheck both published bytes and the owner's negative marker.
            }

            if ($coalescedResponse instanceof Response) {
                return $coalescedResponse;
            }

            $cachedResponse = $this->cachedResponseAfterWaiting($pageCache, $request);

            if ($cachedResponse instanceof Response) {
                return $cachedResponse;
            }

            if (! $coalescingRequired || $coalescedResponse === HtmlCacheRenderLockStatus::Unavailable || ! $this->shouldCoalesce($request)) {
                return $this->handleCacheMiss($pageCache, $request, $next);
            }

            return $this->coalescingRetryResponse($request);
        }

        return $this->handleCacheMiss($pageCache, $request, $next);
    }

    /**
     * The shared marker can change or expire while this request waits.
     *
     * @phpstan-impure
     */
    private function shouldCoalesce(Request $request): bool
    {
        if (config('capell-html-cache.request_coalescing.enabled', true) !== true
            || config('capell-html-cache.write_enabled', true) !== true) {
            return false;
        }

        try {
            return Cache::get($this->uncacheableMarkerKey($request)) !== true;
        } catch (Throwable $throwable) {
            report($throwable);

            return false;
        }
    }

    private function uncacheableMarkerKey(Request $request): string
    {
        return 'capell-html-cache:render:uncacheable:' . hash('sha256', $request->fullUrl());
    }

    private function handleCoalescedCacheMiss(PageCache $pageCache, Request $request, Closure $next): Response
    {
        $cachedResponse = $this->cachedResponseAfterWaiting($pageCache, $request);

        if ($cachedResponse instanceof Response) {
            return $cachedResponse;
        }

        try {
            return $this->handleCacheMiss($pageCache, $request, $next);
        } finally {
            if ($request->attributes->get(self::CACHE_WRITE_SUCCEEDED_ATTRIBUTE) !== true) {
                try {
                    Cache::put(
                        $this->uncacheableMarkerKey($request),
                        true,
                        $this->positiveConfigInteger('capell-html-cache.request_coalescing.uncacheable_seconds', 5),
                    );
                } catch (Throwable $throwable) {
                    report($throwable);
                }
            }
        }
    }

    private function handleCacheMiss(PageCache $pageCache, Request $request, Closure $next): Response
    {
        resolve(HtmlCachePublicationGuard::class)->capture($request);
        $response = $this->renderWithFragmentCapture($request, $next);
        $response = $this->stripCookiesForCacheableAnonymousRequest($request, $response);

        if ($this->containsUnsafeSharedHtml($request, $response)) {
            $response->headers->set('X-Frontend-Cache', 'BYPASS');

            return $this->privateNoStore($response, $request);
        }

        $cached = $this->cacheResponse($pageCache, $request, $response);
        $request->attributes->set(self::CACHE_WRITE_SUCCEEDED_ATTRIBUTE, $cached);
        $response->headers->set('X-Frontend-Cache', 'MISS');

        if ($request->attributes->get(HtmlCachePublicationGuard::REJECTED_ATTRIBUTE) === true) {
            return $this->privateNoStore($response, $request);
        }

        if ($cached) {
            $this->stripConfiguredCookies($response);
        }

        return $this->applyCacheHeaders(
            $request,
            $response,
            forcePublic: $cached && ! $this->hasFragmentCacheData($request),
        );
    }

    private function cachedResponseAfterWaiting(PageCache $pageCache, Request $request): ?Response
    {
        $cachedPage = $pageCache->getCachePage($request);

        if (is_string($cachedPage)) {
            $response = $this->cachedPageResponse($pageCache, $request, $cachedPage, Response::HTTP_OK);
            RecordHtmlCacheHitAction::run($request, strlen((string) $response->getContent()));
            ScheduleOriginStaleCachedUrlRefreshAction::run($request->fullUrl());

            return $response;
        }

        $cachedErrorPage = $pageCache->getCacheErrorPage($request);

        if (is_string($cachedErrorPage)) {
            RecordHtmlCacheHitAction::run($request, strlen($cachedErrorPage));
            ScheduleOriginStaleCachedUrlRefreshAction::run($request->fullUrl());

            return $this->cachedPageResponse($pageCache, $request, $cachedErrorPage, Response::HTTP_NOT_FOUND, PageCache::ERROR_EXTENSION);
        }

        return null;
    }

    private function containsUnsafeSharedHtml(Request $request, Response $response): bool
    {
        if (! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            return false;
        }

        $content = $this->sharedContent($request, $response);

        try {
            $inspector = resolve(PublicHtmlSafetyInspector::class);

            if (! $this->hasMatchingSafeInspection($request, $content)
                && $inspector->containsAuthoringSurface($content)) {
                return true;
            }

            return ! method_exists($inspector, 'containsBakedCsrfToken')
                || $inspector->containsBakedCsrfToken($content);
        } catch (Throwable) {
            return true;
        }
    }

    private function hasMatchingSafeInspection(Request $request, string $content): bool
    {
        return $request->attributes->get(AssertPublicHtmlContainsNoAuthoringSurfaceAction::SAFE_INSPECTION_PASSED_ATTRIBUTE) === true
            && $request->attributes->get(AssertPublicHtmlContainsNoAuthoringSurfaceAction::SAFE_INSPECTION_HASH_ATTRIBUTE) === hash('xxh128', $content);
    }

    private function privateNoStore(Response $response, Request $request): Response
    {
        $request->attributes->set(self::PRIVATE_RESPONSE_ATTRIBUTE, true);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }

    private function shouldBypassForAccessGate(Request $request): bool
    {
        if ($request->attributes->get('access_gate.protected') === true) {
            return true;
        }

        if ($this->hasAccessGateBrowserToken($request)) {
            return true;
        }

        return resolve(ActiveAccessGateAreaResolver::class)->hasActiveArea();
    }

    private function hasAccessGateBrowserToken(Request $request): bool
    {
        $cookieName = config('access-gate.cookies.browser_token.name', 'capell_access_gate_browser_token');

        if (! is_string($cookieName) || $cookieName === '') {
            return false;
        }

        if ($request->cookies->has($cookieName)) {
            return true;
        }

        $cookieHeader = $request->headers->get('Cookie');

        return is_string($cookieHeader) && str_contains($cookieHeader, $cookieName . '=');
    }

    private function shouldBypassHttpCache(Request $request, Response $response): bool
    {
        if (($request->query->count() > 0 && ! StatelessPaginationRequest::isCacheableVariant($request))
            || $this->isInertiaRequest($request)
            || $response->isServerError()) {
            return true;
        }

        return ! resolve(PublicResponseCachePolicy::class)->isCacheable($response);
    }

    private function shouldBypassCacheRead(Request $request): bool
    {
        if (! $request->isMethod('GET')) {
            return true;
        }

        if ($request->query->has('without_html_cache')) {
            return true;
        }

        if ($request->query->count() > 0 && ! StatelessPaginationRequest::isCacheableVariant($request)) {
            return true;
        }

        if (resolve(ConfiguredHtmlCacheBypassRules::class)->shouldBypass($request)) {
            return true;
        }

        if ($request->headers->has('X-Livewire') || $this->isInertiaRequest($request)) {
            return true;
        }

        if (config('capell-html-cache.cache_skip_authenticated', true) === true
            && ($this->sessionCookieRequiresPrivateResponse($request) || $request->user() !== null)) {
            return true;
        }

        if ($request->query->has('signature')) {
            return true;
        }

        return $request->headers->has('Authorization');
    }

    private function shouldForceCacheReadBypass(Request $request): bool
    {
        return $request->attributes->get(self::BYPASS_CACHE_READ_ATTRIBUTE) === true;
    }

    private function isInertiaRequest(Request $request): bool
    {
        if ($request->headers->has('X-Inertia')) {
            return true;
        }

        if ($request->headers->has('X-Inertia-Version')) {
            return true;
        }

        if ($request->headers->has('X-Inertia-Partial-Component')) {
            return true;
        }

        if ($request->headers->has('X-Inertia-Partial-Data')) {
            return true;
        }

        return $request->headers->has('X-Inertia-Reset');
    }

    private function cacheResponse(PageCache $pageCache, Request $request, Response $response): bool
    {
        if ($request->attributes->get(self::FRAGMENT_RENDER_FAILED_ATTRIBUTE) === true) {
            return false;
        }

        $fragmentRegistry = $this->fragmentRegistry();

        if ($fragmentRegistry?->references() !== []) {
            if (! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
                return false;
            }

            if (! $this->hasFragmentCacheData($request)) {
                return false;
            }
        }

        $report = BuildHtmlCacheEligibilityReportAction::run($request, $response);
        $request->attributes->set(self::ELIGIBILITY_REPORT_ATTRIBUTE, $report);

        if (! $report->eligible) {
            return false;
        }

        try {
            return $pageCache->cache($request, $response);
        } catch (Throwable $throwable) {
            report($throwable);

            return false;
        }
    }

    private function renderWithFragmentCapture(Request $request, Closure $next): Response
    {
        $fragmentRegistry = $this->fragmentRegistry();

        if (! $fragmentRegistry instanceof RenderHookFragmentRegistry) {
            return $next($request);
        }

        $fragmentRegistry->beginCapture();

        try {
            $response = $next($request);
            $captured = (string) $response->getContent();

            if ($fragmentRegistry->references() === []) {
                return $response;
            }

            try {
                $response->setContent($fragmentRegistry->renderLive(
                    $captured,
                    resolve(RenderHookRegistry::class),
                    static fn (): string => '',
                ));
            } catch (Throwable $throwable) {
                report($throwable);
                $request->attributes->set(self::FRAGMENT_RENDER_FAILED_ATTRIBUTE, true);
                $response->setContent($this->removeFragmentTokens($captured, $fragmentRegistry));

                return $response;
            }

            if ($fragmentRegistry->hasFailures()) {
                $request->attributes->set(self::FRAGMENT_RENDER_FAILED_ATTRIBUTE, true);

                return $response;
            }

            try {
                $fragmentCache = $fragmentRegistry->prepareCache(
                    $captured,
                    static fn (string $html): string => resolve(HtmlMinifier::class)->minify($html),
                );
                $request->attributes->set(self::FRAGMENT_CACHE_DATA_ATTRIBUTE, $fragmentCache);
            } catch (Throwable $throwable) {
                report($throwable);
                $request->attributes->set(self::FRAGMENT_RENDER_FAILED_ATTRIBUTE, true);
            }

            return $response;
        } finally {
            $fragmentRegistry->endCapture();
        }
    }

    private function fragmentRegistry(): ?RenderHookFragmentRegistry
    {
        return app()->bound(RenderHookFragmentRegistry::class)
            ? resolve(RenderHookFragmentRegistry::class)
            : null;
    }

    private function hasFragmentCacheData(Request $request): bool
    {
        return $request->attributes->get(self::FRAGMENT_CACHE_DATA_ATTRIBUTE) instanceof RenderHookFragmentCacheData;
    }

    private function sharedContent(Request $request, Response $response): string
    {
        $fragmentCache = $request->attributes->get(self::FRAGMENT_CACHE_DATA_ATTRIBUTE);

        return $fragmentCache instanceof RenderHookFragmentCacheData
            ? $fragmentCache->shell
            : (string) $response->getContent();
    }

    private function removeFragmentTokens(string $content, RenderHookFragmentRegistry $fragmentRegistry): string
    {
        foreach ($fragmentRegistry->references() as $reference) {
            $content = str_replace($reference->token, '', $content);
        }

        return $content;
    }

    private function cachedPageResponse(PageCache $pageCache, Request $request, string $content, int $statusCode, string $extension = '.html'): Response
    {
        $fragmentCache = $pageCache->getCacheFragmentData($request, $extension);

        if (! $fragmentCache instanceof RenderHookFragmentCacheData) {
            return $this->cacheHitResponse($request, $content, $statusCode);
        }

        if ($request->attributes->get(self::SHELL_VERIFICATION_ATTRIBUTE) === true) {
            return $this->cacheHitResponse($request, $fragmentCache->shell, $statusCode, fragmented: true);
        }

        try {
            $fragmentRegistry = $this->fragmentRegistry();
            $renderHookRegistry = app()->bound(RenderHookRegistry::class) ? resolve(RenderHookRegistry::class) : null;

            if (! $fragmentRegistry instanceof RenderHookFragmentRegistry || ! $renderHookRegistry instanceof RenderHookRegistry) {
                throw new RuntimeException('Render hook fragment services are unavailable for a fragmented cache hit.');
            }

            $content = $fragmentRegistry->renderCached(
                $fragmentCache,
                $renderHookRegistry,
                static fn (): string => '',
            );
        } catch (Throwable $throwable) {
            report($throwable);
            $content = $fragmentCache->shell;
        }

        return $this->cacheHitResponse($request, $content, $statusCode, fragmented: true);
    }

    private function cacheHitResponse(Request $request, string $content, int $statusCode, bool $fragmented = false): Response
    {
        $response = $this->stripConfiguredCookies(response($content, $statusCode));
        $response->headers->set('Content-Type', 'text/html');
        $response->headers->set('X-Frontend-Cache', 'HIT');

        if ($fragmented) {
            return $this->privateNoStore($response, $request);
        }

        return $this->applyCacheHeaders($request, $response, forcePublic: true);
    }

    private function coalescingRetryResponse(Request $request): Response
    {
        return $this->privateNoStore(response(
            __('capell-html-cache::cache.retry'),
            Response::HTTP_SERVICE_UNAVAILABLE,
            [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Retry-After' => $this->positiveConfigInteger('capell-html-cache.request_coalescing.wait_seconds', 3),
            ],
        ), $request);
    }

    private function applyCacheHeaders(
        Request $request,
        Response $response,
        bool $applySurrogateKey = true,
        bool $forcePublic = false,
    ): Response {
        if (! $forcePublic && $request->attributes->get(self::FRAGMENT_RENDER_FAILED_ATTRIBUTE) === true) {
            return $this->privateNoStore($response, $request);
        }

        if (! $forcePublic && $this->hasFragmentCacheData($request)) {
            return $this->privateNoStore($response, $request);
        }

        if (! $forcePublic && $this->shouldBypassHttpCache($request, $response)) {
            return $this->privateNoStore($response, $request);
        }

        if (! $forcePublic && $this->eligibilityReport($request)->hasReason(HtmlCacheEligibilityReason::PackageCacheBlocking)) {
            return $this->privateNoStore($response, $request);
        }

        if (! $forcePublic && $this->eligibilityReport($request)->hasReason(HtmlCacheEligibilityReason::PackageSensitiveOutput)) {
            return $this->privateNoStore($response, $request);
        }

        if (! $forcePublic && (
            ! $request->isMethod('GET')
            || $this->sessionCookieRequiresPrivateResponse($request)
            || $request->headers->has('Authorization')
        )) {
            return $this->privateNoStore($response, $request);
        }

        if (! $forcePublic && str_contains((string) $response->headers->get('Cache-Control'), 'public')) {
            return $response;
        }

        if (! $forcePublic) {
            return $this->privateNoStore($response, $request);
        }

        $response->headers->set('Cache-Control', sprintf(
            'public, s-maxage=%d, max-age=%d, stale-while-revalidate=%d',
            $this->sharedMaxAge(),
            $this->browserMaxAge(),
            $this->staleWhileRevalidateSeconds(),
        ));
        $vary = implode(', ', config('capell-html-cache.cache_vary_headers', ['Accept-Encoding']));

        // Stale refresh validates this response again after middleware has written it.
        if (trim($vary) === '') {
            $response->headers->remove('Vary');
        } else {
            $response->headers->set('Vary', $vary);
        }

        if ($applySurrogateKey) {
            $this->applySurrogateKey($request, $response);
        }

        return $response;
    }

    private function applySurrogateKey(Request $request, Response $response): void
    {
        $keys = [];

        try {
            $context = resolve(FrontendContextReader::class);

            if ($context->page() instanceof Pageable) {
                $keys[] = 'page-' . $context->page()->getKey();
            }

            if ($context->site() instanceof Site) {
                $keys[] = 'site-' . $context->site()->getKey();
            }

            if ($context->language() instanceof Language) {
                $keys[] = 'lang-' . $context->language()->code;
            }
        } catch (Exception) {
            // Frontend context is optional for non-page responses.
        }

        $keys = [
            ...$keys,
            ...ResolveEdgeCacheTagsAction::run($request),
            ...resolve(ExtensionCacheSafetyResolver::class)->cacheTags(),
        ];

        $keys = SurrogateKeyNormalizer::normalize($keys);

        if ($keys !== []) {
            $response->headers->set('Surrogate-Key', implode(' ', $keys));
            $response->headers->set('Cache-Tag', implode(',', $keys));
        }
    }

    private function eligibilityReport(Request $request): HtmlCacheEligibilityReportData
    {
        $report = $request->attributes->get(self::ELIGIBILITY_REPORT_ATTRIBUTE);

        if ($report instanceof HtmlCacheEligibilityReportData) {
            return $report;
        }

        $report = BuildHtmlCacheEligibilityReportAction::run($request);
        $request->attributes->set(self::ELIGIBILITY_REPORT_ATTRIBUTE, $report);

        return $report;
    }

    private function hasSessionCookie(Request $request): bool
    {
        $sessionCookieName = config('session.cookie');

        return is_string($sessionCookieName)
            && $sessionCookieName !== ''
            && $request->cookies->has($sessionCookieName);
    }

    private function hasIncomingSessionCookie(Request $request): bool
    {
        return $request->attributes->get(self::INCOMING_SESSION_COOKIE_ATTRIBUTE, false) === true;
    }

    private function sessionCookieRequiresPrivateResponse(Request $request): bool
    {
        if (! $this->hasIncomingSessionCookie($request)) {
            return false;
        }

        if ($request->user() !== null) {
            return true;
        }

        $privatePaths = config('capell-html-cache.anonymous_session_cookie.private_paths', []);

        if (is_array($privatePaths) && $privatePaths !== [] && $request->is(...$privatePaths)) {
            return true;
        }

        $sharedPaths = config('capell-html-cache.anonymous_session_cookie.shared_paths', []);

        return ! is_array($sharedPaths) || $sharedPaths === [] || ! $request->is(...$sharedPaths);
    }

    private function stripCookiesForCacheableAnonymousRequest(Request $request, Response $response): Response
    {
        if (! $request->isMethod('GET') || $this->sessionCookieRequiresPrivateResponse($request) || $request->headers->has('Authorization')) {
            return $response;
        }

        if (! in_array($response->getStatusCode(), [Response::HTTP_OK, Response::HTTP_NOT_FOUND], true)) {
            return $response;
        }

        if (! resolve(ExtensionCacheSafetyResolver::class)->isPublicCacheSafe()) {
            return $response;
        }

        return $this->stripConfiguredCookies($response);
    }

    private function stripConfiguredCookies(Response $response): Response
    {
        return CacheableResponseCookieStripper::strip($response);
    }

    private function sharedMaxAge(): int
    {
        $configuredSharedMaxAge = config('capell-html-cache.http_cache.shared_max_age');

        if (is_numeric($configuredSharedMaxAge)) {
            return max(0, (int) $configuredSharedMaxAge);
        }

        $configuredCacheTtl = config('capell-html-cache.cache_ttl');
        $cacheTtl = is_numeric($configuredCacheTtl) ? max(0, (int) $configuredCacheTtl) : 3600;

        return intdiv($cacheTtl, 6);
    }

    private function browserMaxAge(): int
    {
        return $this->nonNegativeConfigInteger('capell-html-cache.http_cache.browser_max_age', 60);
    }

    private function staleWhileRevalidateSeconds(): int
    {
        return $this->nonNegativeConfigInteger('capell-html-cache.http_cache.stale_while_revalidate', 86400);
    }

    private function nonNegativeConfigInteger(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_numeric($value) ? max(0, (int) $value) : $default;
    }

    private function positiveConfigInteger(string $key, int $default): int
    {
        return max(1, $this->nonNegativeConfigInteger($key, $default));
    }
}
