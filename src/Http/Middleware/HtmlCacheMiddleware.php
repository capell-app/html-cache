<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Http\Middleware;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Frontend\Actions\AssertPublicHtmlContainsNoAuthoringSurfaceAction;
use Capell\Frontend\Contracts\CacheBypassResolver;
use Capell\Frontend\Support\Cache\SurrogateKeyNormalizer;
use Capell\Frontend\Support\Context\FrontendContext;
use Capell\Frontend\Support\Security\PublicHtmlSafetyInspector;
use Capell\HtmlCache\Actions\BuildHtmlCacheEligibilityReportAction;
use Capell\HtmlCache\Actions\RecordHtmlCacheHitAction;
use Capell\HtmlCache\Actions\RefreshOriginStaleCachedUrlAction;
use Capell\HtmlCache\Data\HtmlCacheEligibilityReportData;
use Capell\HtmlCache\Enums\HtmlCacheEligibilityReason;
use Capell\HtmlCache\Support\AccessGate\ActiveAccessGateAreaResolver;
use Capell\HtmlCache\Support\Cache\CacheableResponseCookieStripper;
use Capell\HtmlCache\Support\Cache\ConfiguredHtmlCacheBypassRules;
use Capell\HtmlCache\Support\Cache\PageCache;
use Capell\HtmlCache\Support\Extensions\ExtensionCacheSafetyResolver;
use Closure;
use Exception;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class HtmlCacheMiddleware
{
    public const string BYPASS_CACHE_READ_ATTRIBUTE = 'capell.html_cache.bypass_cache_read';

    public const string CACHE_WRITE_SUCCEEDED_ATTRIBUTE = 'capell.html_cache.cache_write_succeeded';

    public const string STALE_CACHE_ID_ATTRIBUTE = 'capell.html_cache.stale_cache_id';

    public const string STALE_CACHE_CLAIM_TOKEN_ATTRIBUTE = 'capell.html_cache.stale_cache_claim_token';

    public const string ELIGIBILITY_REPORT_ATTRIBUTE = 'capell.html_cache.eligibility_report';

    private const string INCOMING_SESSION_COOKIE_ATTRIBUTE = 'capell.html_cache.incoming_session_cookie';

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(self::INCOMING_SESSION_COOKIE_ATTRIBUTE, $this->hasSessionCookie($request));

        if (resolve(ConfiguredHtmlCacheBypassRules::class)->shouldBypass($request)) {
            return $this->privateNoStore($next($request));
        }

        if ($this->shouldBypassForAccessGate($request)) {
            return $this->privateNoStore($next($request));
        }

        if (resolve(CacheBypassResolver::class)->shouldBypass()) {
            return $next($request);
        }

        if (config('capell-html-cache.enabled', true) !== true) {
            return $this->applyCacheHeaders($request, $next($request));
        }

        $forceCacheReadBypass = $this->shouldForceCacheReadBypass($request);

        if (! $forceCacheReadBypass && $this->shouldBypassCacheRead($request)) {
            $response = $next($request);
            $request->attributes->set(
                self::ELIGIBILITY_REPORT_ATTRIBUTE,
                BuildHtmlCacheEligibilityReportAction::run($request, $response),
            );

            if ($this->shouldBypassHttpCache($request, $response)) {
                return $this->privateNoStore($response);
            }

            return $this->applyCacheHeaders($request, $response);
        }

        $pageCache = resolve(PageCache::class);

        if (! $forceCacheReadBypass) {
            $cachedPage = $pageCache->getCachePage($request);

            if (is_string($cachedPage)) {
                RecordHtmlCacheHitAction::run($request, strlen($cachedPage));
                $this->refreshStaleCachedUrlAfterResponse($request);

                return $this->cacheHitResponse($cachedPage, 200);
            }

            $cachedErrorPage = $pageCache->getCacheErrorPage($request);

            if (is_string($cachedErrorPage)) {
                RecordHtmlCacheHitAction::run($request, strlen($cachedErrorPage));
                $this->refreshStaleCachedUrlAfterResponse($request);

                return $this->cacheHitResponse($cachedErrorPage, 404);
            }
        }

        $response = $this->stripCookiesForCacheableAnonymousRequest($request, $next($request));

        if ($this->containsAuthoringSurface($request, $response)) {
            $response->headers->set('X-Frontend-Cache', 'BYPASS');

            return $this->privateNoStore($response);
        }

        $cached = $this->cacheResponse($pageCache, $request, $response);
        $request->attributes->set(self::CACHE_WRITE_SUCCEEDED_ATTRIBUTE, $cached);

        if ($cached) {
            $this->stripConfiguredCookies($response);
        }

        $response->headers->set('X-Frontend-Cache', 'MISS');

        return $this->applyCacheHeaders($request, $response, forcePublic: $cached);
    }

    private function containsAuthoringSurface(Request $request, Response $response): bool
    {
        if (mb_strpos((string) $response->headers->get('Content-Type'), 'text/html') === false) {
            return false;
        }

        $content = (string) $response->getContent();

        if ($this->hasMatchingSafeInspection($request, $content)) {
            return false;
        }

        return resolve(PublicHtmlSafetyInspector::class)->containsAuthoringSurface($content);
    }

    private function hasMatchingSafeInspection(Request $request, string $content): bool
    {
        return $request->attributes->get(AssertPublicHtmlContainsNoAuthoringSurfaceAction::SAFE_INSPECTION_PASSED_ATTRIBUTE) === true
            && $request->attributes->get(AssertPublicHtmlContainsNoAuthoringSurfaceAction::SAFE_INSPECTION_HASH_ATTRIBUTE) === hash('xxh128', $content);
    }

    private function privateNoStore(Response $response): Response
    {
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
        if ($request->query->count() > 0 || $this->isInertiaRequest($request) || $response->isServerError()) {
            return true;
        }

        return str_contains((string) $response->headers->get('Cache-Control'), 'no-store');
    }

    private function shouldBypassCacheRead(Request $request): bool
    {
        if (! $request->isMethod('GET')) {
            return true;
        }

        if ($request->query->has('without_html_cache') || $request->query->count() > 0) {
            return true;
        }

        if (resolve(ConfiguredHtmlCacheBypassRules::class)->shouldBypass($request)) {
            return true;
        }

        if ($request->headers->has('X-Livewire') || $this->isInertiaRequest($request)) {
            return true;
        }

        if (config('capell-html-cache.cache_skip_authenticated', true) === true
            && ($this->hasIncomingSessionCookie($request) || $request->user() !== null)) {
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
        $report = BuildHtmlCacheEligibilityReportAction::run($request, $response);
        $request->attributes->set(self::ELIGIBILITY_REPORT_ATTRIBUTE, $report);

        if (! $report instanceof HtmlCacheEligibilityReportData || ! $report->eligible) {
            return false;
        }

        try {
            $pageCache->cache($request, $response);
        } catch (Throwable $throwable) {
            report($throwable);

            return false;
        }

        return true;
    }

    private function cacheHitResponse(string $content, int $statusCode): Response
    {
        $response = $this->stripConfiguredCookies(response($content, $statusCode));
        $response->headers->set('Content-Type', 'text/html');
        $response->headers->set('X-Frontend-Cache', 'HIT');

        return $this->applyCacheHeaders(request(), $response, applySurrogateKey: false, forcePublic: true);
    }

    private function refreshStaleCachedUrlAfterResponse(Request $request): void
    {
        if (config('capell-html-cache.origin_stale_while_revalidate.enabled', true) !== true) {
            return;
        }

        if (app()->runningUnitTests() || app()->runningInConsole()) {
            RefreshOriginStaleCachedUrlAction::dispatchSync($request->fullUrl());

            return;
        }

        RefreshOriginStaleCachedUrlAction::dispatchAfterResponse($request->fullUrl());
    }

    private function applyCacheHeaders(
        Request $request,
        Response $response,
        bool $applySurrogateKey = true,
        bool $forcePublic = false,
    ): Response {
        if (! $forcePublic && $this->shouldBypassHttpCache($request, $response)) {
            return $this->privateNoStore($response);
        }

        if (! $forcePublic && $this->eligibilityReport($request)->hasReason(HtmlCacheEligibilityReason::PackageCacheBlocking)) {
            return $this->privateNoStore($response);
        }

        if (! $forcePublic && $this->eligibilityReport($request)->hasReason(HtmlCacheEligibilityReason::PackageSensitiveOutput)) {
            return $this->privateNoStore($response);
        }

        if (! $forcePublic && (
            ! $request->isMethod('GET')
            || $this->hasIncomingSessionCookie($request)
            || $request->headers->has('Authorization')
        )) {
            $response->headers->set('Cache-Control', 'private, no-store');

            return $response;
        }

        if (! $forcePublic && str_contains((string) $response->headers->get('Cache-Control'), 'public')) {
            return $response;
        }

        if (! $forcePublic) {
            return $this->privateNoStore($response);
        }

        $response->headers->set('Cache-Control', sprintf(
            'public, s-maxage=%d, max-age=%d, stale-while-revalidate=%d',
            $this->sharedMaxAge(),
            $this->browserMaxAge(),
            $this->staleWhileRevalidateSeconds(),
        ));
        $response->headers->set('Vary', implode(', ', config('capell-html-cache.cache_vary_headers', ['Accept-Encoding'])));

        if ($applySurrogateKey) {
            $this->applySurrogateKey($response);
        }

        return $response;
    }

    private function applySurrogateKey(Response $response): void
    {
        $keys = [];

        try {
            $context = FrontendContext::current();

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
            ...resolve(ExtensionCacheSafetyResolver::class)->cacheTags(),
        ];

        $keys = SurrogateKeyNormalizer::normalize($keys);

        if ($keys !== []) {
            $response->headers->set('Surrogate-Key', implode(' ', $keys));
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

    private function stripCookiesForCacheableAnonymousRequest(Request $request, Response $response): Response
    {
        if (! $request->isMethod('GET') || $this->hasIncomingSessionCookie($request) || $request->headers->has('Authorization')) {
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
}
