<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Listeners;

use Capell\HtmlCache\Actions\ClearAllHtmlCacheAction;

final class RemediatePrivateSiteSpecHtmlCache
{
    public function handle(): void
    {
        // Index recording is asynchronous; the affected sites may still have
        // unindexed files or historical domain roots. Withdraw the whole store
        // under its publication guard before acknowledging the edge purge.
        ClearAllHtmlCacheAction::run(awaitEdgePurge: true);
    }
}
