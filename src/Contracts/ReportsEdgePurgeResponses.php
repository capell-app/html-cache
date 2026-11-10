<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Contracts;

use Capell\HtmlCache\Data\EdgeCachePurgeData;
use Capell\HtmlCache\Data\EdgePurgeResponseData;

/**
 * An edge purger whose queued purges can be coalesced and paced per zone.
 * Purgers that only implement CachePurger keep the direct, unthrottled path.
 */
interface ReportsEdgePurgeResponses extends CachePurger
{
    /**
     * The provider rate-limit scope these purges share, such as one
     * Cloudflare zone, or null when the purger is not configured.
     */
    public function edgePurgeZone(): ?string;

    /**
     * Send one purge and report how the provider answered, including its
     * status and any Retry-After or rate-limit headers.
     */
    public function sendPurge(EdgeCachePurgeData $purge): EdgePurgeResponseData;
}
