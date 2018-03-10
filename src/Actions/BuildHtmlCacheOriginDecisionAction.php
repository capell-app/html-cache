<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Frontend\Data\RenderHookFragmentCacheData;
use Capell\HtmlCache\Data\HtmlCacheEligibilityReportData;
use Capell\HtmlCache\Data\HtmlCacheOriginDecisionData;
use Capell\HtmlCache\Enums\HtmlCacheEligibilityReason;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Support\Cache\CacheableResponseCookieStripper;
use Capell\HtmlCache\Support\Cache\ConfiguredHtmlCacheBypassRules;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Capell\HtmlCache\Support\Cache\PublicResponseCachePolicy;
use Capell\HtmlCache\Support\Extensions\ExtensionCacheSafetyResolver;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpFoundation\Response;

/** @method static HtmlCacheOriginDecisionData run(Request $request, Response $response) */
final class BuildHtmlCacheOriginDecisionAction
{
    use AsFake;
    use AsObject;

    public function handle(Request $request, Response $response): HtmlCacheOriginDecisionData
    {
        // Retirement inspects cache policy without changing the private origin response or its cookies.
        $response = CacheableResponseCookieStripper::strip(clone $response);

        if (! HtmlCacheEligibilityReason::acceptsRefreshStatus($response->getStatusCode())
            || ! resolve(HtmlCachePathResolver::class)->hasSafeKey($request)
            || config('capell-html-cache.enabled', true) !== true
            || config('capell-html-cache.write_enabled', true) !== true
            || $request->attributes->get(HtmlCacheMiddleware::FRAGMENT_RENDER_FAILED_ATTRIBUTE) === true) {
            return new HtmlCacheOriginDecisionData;
        }

        // A private assembled fragment response can still have a freshly published public shell.
        if ($request->attributes->get(HtmlCacheMiddleware::CACHE_WRITE_SUCCEEDED_ATTRIBUTE) === true
            && $request->attributes->get(HtmlCacheMiddleware::FRAGMENT_CACHE_DATA_ATTRIBUTE) instanceof RenderHookFragmentCacheData) {
            return new HtmlCacheOriginDecisionData;
        }

        $report = $request->attributes->get(HtmlCacheMiddleware::ELIGIBILITY_REPORT_ATTRIBUTE);
        // Reuse the original rejection instead of inspecting unsafe bytes a second time.
        if (! $this->hasDeterministicOriginPolicy($request, $response)) {
            return new HtmlCacheOriginDecisionData(
                rejectionReason: HtmlCacheOriginDecisionData::forRequest($request)->rejectionReason
                ?? ($report instanceof HtmlCacheEligibilityReportData ? ($report->reasons[0] ?? null) : null),
            );
        }
        if (! $report instanceof HtmlCacheEligibilityReportData) {
            $report = BuildHtmlCacheEligibilityReportAction::run($request, $response);
        }

        if ($report->hasReason(HtmlCacheEligibilityReason::StaleClaimInvalid)) {
            return new HtmlCacheOriginDecisionData;
        }

        // A visitor's private response says nothing about the anonymous URL's policy.
        foreach ([
            HtmlCacheEligibilityReason::NonGetRequest,
            HtmlCacheEligibilityReason::SignedPreviewRequest,
            HtmlCacheEligibilityReason::AuthenticatedOrSessionRequest,
            HtmlCacheEligibilityReason::LivewireRequest,
            HtmlCacheEligibilityReason::InertiaRequest,
            HtmlCacheEligibilityReason::AuthorizationHeaderPresent,
            HtmlCacheEligibilityReason::SessionUserState,
            HtmlCacheEligibilityReason::CacheBypassResolver,
        ] as $requestReason) {
            if ($report->hasReason($requestReason)) {
                return new HtmlCacheOriginDecisionData;
            }
        }

        if ($report->hasReason(HtmlCacheEligibilityReason::ConfiguredBypassRule)
            && ! resolve(ConfiguredHtmlCacheBypassRules::class)->shouldBypassUrl($request->fullUrl())) {
            return new HtmlCacheOriginDecisionData;
        }

        $reasons = $response->isRedirection() ? [HtmlCacheEligibilityReason::RedirectUrl] : $report->reasons;
        foreach ($reasons as $reason) {
            if ($reason->isDeterministicForStaleRefresh($response->getStatusCode())) {
                return new HtmlCacheOriginDecisionData(retirementReason: $reason);
            }
        }

        return new HtmlCacheOriginDecisionData;
    }

    private function hasDeterministicOriginPolicy(Request $request, Response $response): bool
    {
        if ($response->isRedirection()
            || ! str_contains((string) $response->headers->get('Content-Type'), 'text/html')
            || resolve(ConfiguredHtmlCacheBypassRules::class)->shouldBypassUrl($request->fullUrl())
            || ! resolve(ExtensionCacheSafetyResolver::class)->isPublicCacheSafe()) {
            return true;
        }

        return array_any(
            resolve(PublicResponseCachePolicy::class)->reasons($response),
            static fn (HtmlCacheEligibilityReason $reason): bool => $reason->isDeterministicForStaleRefresh($response->getStatusCode()),
        );
    }
}
