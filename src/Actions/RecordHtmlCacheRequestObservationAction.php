<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\HtmlCache\Enums\HtmlCacheEligibilityReason;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Support\AccessGate\ActiveAccessGateAreaResolver;
use Capell\HtmlCache\Support\Cache\ConfiguredHtmlCacheBypassRules;
use Capell\HtmlCache\Support\Telemetry\HtmlCacheRequestObservationStore;
use Illuminate\Http\Request;
use Illuminate\Support\Lottery;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** @method static void run(Request $request, Response $response, ?float $renderMilliseconds) */
final class RecordHtmlCacheRequestObservationAction
{
    use AsFake;
    use AsObject;

    public function handle(Request $request, Response $response, ?float $renderMilliseconds): void
    {
        try {
            if (config('capell-html-cache.observation_recording.enabled', true) !== true
                || ! $request->isMethod('GET') || $request->query->count() !== 0
                || $request->headers->has('Authorization') || $request->user() !== null
                || $request->attributes->get(HtmlCacheMiddleware::SYNTHETIC_RENDER_ATTRIBUTE) === true
                || $request->attributes->get('access_gate.protected') === true
                || $response->getStatusCode() !== Response::HTTP_OK
                || ! str_contains(strtolower((string) $response->headers->get('Content-Type')), 'text/html')) {
                return;
            }

            $configuredRate = config('capell-html-cache.observation_recording.sample_rate', 10);
            $rate = max(1, is_numeric($configuredRate) ? (int) $configuredRate : 10);
            if ($rate > 1 && Lottery::odds(1, $rate)->choose() !== true) {
                return;
            }

            // Private cache headers also describe anonymous disabled-cache
            // pages. Use visitor eligibility, not cacheability, for observations.
            foreach (resolve(BuildHtmlCacheEligibilityReportAction::class)->requestReasons($request, forCacheWrite: true) as $reason) {
                if ($reason !== HtmlCacheEligibilityReason::ConfiguredBypassRule
                    || ! resolve(ConfiguredHtmlCacheBypassRules::class)->shouldBypassUrl($request->getUri())) {
                    return;
                }
            }

            $accessGate = resolve(ActiveAccessGateAreaResolver::class);
            if ($accessGate->hasBrowserToken($request) || $accessGate->hasActiveArea()) {
                return;
            }

            $pageUrl = ResolveHtmlCachePageUrlAction::run($request);
            if ($pageUrl === null) {
                return;
            }

            resolve(HtmlCacheRequestObservationStore::class)->record(
                HtmlCacheRequestObservationStore::pageUrlHash($pageUrl),
                (int) $pageUrl->site_id,
                (int) $pageUrl->language_id,
                $renderMilliseconds,
                $rate,
            );
        } catch (Throwable $throwable) {
            // Observations cannot interrupt a public response or cache refresh.
            report($throwable);
        }
    }
}
