<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Admin;

use Capell\Admin\Contracts\Cache\StaticSiteGenerationDispatcher;
use Capell\Core\Models\Site;
use Capell\HtmlCache\Actions\GenerateStaticSitesAction;

final class HtmlCacheStaticSiteGenerationDispatcher implements StaticSiteGenerationDispatcher
{
    public function dispatch(Site $site): void
    {
        GenerateStaticSitesAction::run(collect([$site]));
    }
}
