<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Core\Models\PageUrl;
use Capell\HtmlCache\Data\HtmlCacheRequestMetricsData;
use Capell\HtmlCache\Support\Telemetry\HtmlCacheRequestObservationStore;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/** @method static HtmlCacheRequestMetricsData|null run(Request $request, ?PageUrl $pageUrl = null) */
final class ReadHtmlCacheRequestMetricsAction
{
    use AsFake;
    use AsObject;

    public function handle(Request $request, ?PageUrl $pageUrl = null): ?HtmlCacheRequestMetricsData
    {
        try {
            $pageUrl ??= ResolveHtmlCachePageUrlAction::run($request);

            return $pageUrl === null ? null : resolve(HtmlCacheRequestObservationStore::class)->read(
                HtmlCacheRequestObservationStore::pageUrlHash($pageUrl),
                (int) $pageUrl->site_id,
                (int) $pageUrl->language_id,
            );
        } catch (Throwable $throwable) {
            // Diagnostic telemetry must not break response eligibility checks.
            report($throwable);

            return null;
        }
    }
}
