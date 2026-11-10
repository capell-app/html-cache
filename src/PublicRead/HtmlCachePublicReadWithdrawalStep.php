<?php

declare(strict_types=1);

namespace Capell\HtmlCache\PublicRead;

use Capell\HtmlCache\Actions\ClearAllHtmlCacheAction;

/**
 * Any cached page may link to, list or describe a page whose public-read
 * eligibility just changed, whichever package or area changed it. The whole
 * store is withdrawn under its publication guard's lock, the lock every disk
 * write is checked and written under, so a write that passed its check before
 * the change finishes first and is removed here; the edge purge is awaited.
 * A failure leaves the step owed, and Core retries it.
 */
final class HtmlCachePublicReadWithdrawalStep
{
    public const string KEY = 'html-cache.pages';

    public function __invoke(): void
    {
        ClearAllHtmlCacheAction::run(awaitEdgePurge: true);
    }
}
