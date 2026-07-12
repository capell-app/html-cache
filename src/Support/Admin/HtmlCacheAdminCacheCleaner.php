<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Admin;

use Capell\Admin\Contracts\Cache\AdminCacheCleaner;
use Capell\HtmlCache\Actions\ClearAllHtmlCacheAction;

final class HtmlCacheAdminCacheCleaner implements AdminCacheCleaner
{
    public function clear(): void
    {
        ClearAllHtmlCacheAction::run();
    }
}
