<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\HtmlCache\Models\StaleCachedUrl;
use Capell\HtmlCache\Support\Cache\OriginRefreshLock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
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

    // Long enough for the slots already rendering to drain (a job's time limit is below its slot TTL).
    /** Longer than any slot a dead holder can leave behind, so one wait always outlasts it. */
    public const int LOCK_WAIT_SECONDS = OriginRefreshLock::MAX_SLOT_TTL_SECONDS + 10;

    private const int BATCH_SIZE = 50;

    public function handle(): int
    {
        if (config('capell-html-cache.invalidation.mode', 'instant') === 'scheduled') {
            // The scheduled processor owns refresh timing in this mode.
            return 0;
        }

        $maximum = config('capell-html-cache.origin_stale_while_revalidate.max_stale_age_seconds', 900);
        $cutoff = CarbonImmutable::now()->subSeconds(is_numeric($maximum) ? max(1, (int) $maximum) : 900);
        $expired = 0;
        $lock = resolve(OriginRefreshLock::class);
        $batchCount = self::BATCH_SIZE;

        // Each batch is cleared holding the whole origin refresh lock. Taking its
        // gate makes new background refreshes defer, so steady traffic cannot
        // starve this enforcement; the slots already rendering drain within one
        // render. A deployment refresher holding the lock is replacing these copies
        // itself, so its batch is skipped; the lock's absolute deadline bounds how
        // long that can last, after which maximum-age enforcement resumes.
        do {
            $owner = 'expiry:' . Str::uuid();
            $cleared = $lock->exclusive($owner, 60, self::LOCK_WAIT_SECONDS, null, function () use ($cutoff, $lock, $owner, &$expired): ?int {
                $batch = StaleCachedUrl::query()
                    ->with('siteDomain')
                    ->whereIn('status', [StaleCachedUrl::STATUS_PENDING, StaleCachedUrl::STATUS_FAILED, StaleCachedUrl::STATUS_EXHAUSTED])
                    ->where('created_at', '<', $cutoff)
                    ->orderBy('id')
                    ->limit(self::BATCH_SIZE)
                    ->get();

                foreach ($batch as $stale) {
                    // Stop the moment the lock is no longer ours: a refresher may already be
                    // replacing the rest of this batch.
                    if (! $lock->renew($owner, 60)) {
                        return null;
                    }

                    ClearCachedUrlAction::run($stale->url, $stale->siteDomain);

                    $stale->forceFill([
                        'status' => StaleCachedUrl::STATUS_NOT_APPLICABLE,
                        'claim_token' => null,
                        'last_error' => 'Cleared: no refresh replaced the stale copy within the maximum stale age.',
                    ])->save();

                    $expired++;
                }

                return $batch->count();
            });

            // Null: the lock could not be taken in time, or was lost part-way through the batch.
            if ($cleared === null) {
                break;
            }

            $batchCount = $cleared;
        } while ($batchCount === self::BATCH_SIZE);

        return $expired;
    }
}
