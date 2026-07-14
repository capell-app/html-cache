<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Cache;

use Capell\Frontend\Contracts\FrontendOutputCacheInvalidator;
use Capell\HtmlCache\Actions\ClearAllHtmlCacheAction;

final class HtmlFrontendOutputCacheInvalidator implements FrontendOutputCacheInvalidator
{
    public function invalidateAll(): void
    {
        ClearAllHtmlCacheAction::run();
    }
}
