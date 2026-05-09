<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Admin;

use Capell\Admin\Contracts\Diagnostics\SiteAwareSiteHealthReportExtender;
use Capell\Admin\Data\Diagnostics\DiagnosticSectionData;
use Capell\HtmlCache\Actions\BuildCachedModelUrlDiagnosticsAction;
use Capell\HtmlCache\Actions\BuildHtmlCachePublicOutputSafetyDiagnosticsAction;

final class HtmlCacheSiteHealthReportExtender implements SiteAwareSiteHealthReportExtender
{
    /**
     * @return list<DiagnosticSectionData>
     */
    public function sections(): array
    {
        return $this->sectionsForSite(null);
    }

    /**
     * @return list<DiagnosticSectionData>
     */
    public function sectionsForSite(?int $siteId): array
    {
        return [
            new DiagnosticSectionData(
                label: (string) __('capell-html-cache::admin.site_health_html_cache'),
                checks: [
                    ...BuildHtmlCachePublicOutputSafetyDiagnosticsAction::run($siteId),
                    ...BuildCachedModelUrlDiagnosticsAction::run($siteId),
                ],
            ),
        ];
    }
}
