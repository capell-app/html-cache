<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Data;

use Capell\HtmlCache\Enums\HtmlCacheEligibilityReason;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Illuminate\Http\Request;
use Spatie\LaravelData\Data;

/** The origin cache boundary owns this decision; outer response headers cannot replace it. */
final class HtmlCacheOriginDecisionData extends Data
{
    public function __construct(
        public readonly ?HtmlCacheEligibilityReason $retirementReason = null,
        public readonly bool $cacheWriteSucceeded = false,
        public readonly ?HtmlCacheEligibilityReason $rejectionReason = null,
    ) {}

    public static function forRequest(Request $request): ?self
    {
        $decision = $request->attributes->get(HtmlCacheMiddleware::ORIGIN_DECISION_ATTRIBUTE);

        return $decision instanceof self ? $decision : null;
    }
}
