<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Core\Models\PageUrl;
use Capell\HtmlCache\Data\HtmlCacheEligibilityReportData;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** @method static HtmlCacheEligibilityReportData run(Request $request, ?PageUrl $pageUrl = null, bool $inspectCacheState = true) */
final class BuildAnonymousHtmlCacheEligibilityReportAction
{
    use AsFake;
    use AsObject;

    public function handle(Request $request, ?PageUrl $pageUrl = null, bool $inspectCacheState = true): HtmlCacheEligibilityReportData
    {
        $previousRequest = request();
        app()->instance('request', $request);

        try {
            // Laravel's request rebinding installs the current auth resolver.
            // Set the anonymous resolver afterwards so admin inspections stay public.
            $request->setUserResolver(static fn (): null => null);

            return BuildHtmlCacheEligibilityReportAction::run($request, pageUrl: $pageUrl, inspectCacheState: $inspectCacheState);
        } finally {
            app()->instance('request', $previousRequest);
        }
    }
}
