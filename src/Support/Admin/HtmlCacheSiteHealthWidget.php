<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Admin;

use Capell\Admin\Contracts\Diagnostics\SiteHealthWidgetWithParameters;

final class HtmlCacheSiteHealthWidget implements SiteHealthWidgetWithParameters
{
    public function component(): string
    {
        return 'capell-html-cache.site-health-cache-map';
    }

    public function key(): string
    {
        return 'html-cache-map';
    }

    /** @return array<string, int|null> */
    public function parameters(?int $siteId): array
    {
        return ['siteId' => $siteId];
    }
}
