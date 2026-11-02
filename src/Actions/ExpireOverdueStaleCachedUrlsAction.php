<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\HtmlCache\Models\StaleCachedUrl;
use Carbon\CarbonImmutable;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsJob;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Clears stale copies that no refresh has replaced within the configured
 * maximum age, so a stalled or absent worker cannot keep serving old output.
 *
 * @method static int run()
 */
final class ExpireOverdueStaleCachedUrlsAction
{
    use AsFake;
    use AsJob;
    use AsObject;

    public function handle(): int
    {
        if (config('capell-html-cache.invalidation.mode', 'instant') === 'scheduled') {
            // The scheduled processor owns refresh timing in this mode.
            return 0;
        }

        $maximum = config('capell-html-cache.origin_stale_while_revalidate.max_stale_age_seconds', 900);
        $cutoff = CarbonImmutable::now()->subSeconds(is_numeric($maximum) ? max(1, (int) $maximum) : 900);
        $expired = 0;

        StaleCachedUrl::query()
            ->with('siteDomain')
            ->whereIn('status', [StaleCachedUrl::STATUS_PENDING, StaleCachedUrl::STATUS_FAILED, StaleCachedUrl::STATUS_EXHAUSTED])
            ->where('created_at', '<', $cutoff)
            ->lazyById()
            ->each(function (StaleCachedUrl $stale) use (&$expired): void {
                ClearCachedUrlAction::run($stale->url, $stale->siteDomain);

                $stale->forceFill([
                    'status' => StaleCachedUrl::STATUS_NOT_APPLICABLE,
                    'claim_token' => null,
                    'last_error' => 'Cleared: no refresh replaced the stale copy within the maximum stale age.',
                ])->save();

                $expired++;
            });

        return $expired;
    }
}
