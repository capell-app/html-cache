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

    /** The reason recorded on a row queued to rebuild a copy this action cleared. */
    public const string REGENERATE_REASON = 'expired_regenerate';

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

                    if ($stale->reason === self::REGENERATE_REASON) {
                        // The stale copy was already cleared under the publication guard, so any copy
                        // there now was published since and is current: keep it, and stop rebuilding.
                        // A stale mark that landed since this batch was read wins, so it is not lost.
                        if ($this->updateUnlessMarkedAgain($stale, [
                            'status' => StaleCachedUrl::STATUS_NOT_APPLICABLE,
                            'claim_token' => null,
                            'last_error' => 'The rebuild did not finish within the maximum stale age; the URL is left to the next request.',
                        ])) {
                            $expired++;
                        }

                        continue;
                    }

                    ClearCachedUrlAction::run($stale->url, $stale->siteDomain);

                    // The stale copy is gone, so nothing old can be served. Queue one rebuild so an
                    // unrequested page returns without waiting for a visitor.
                    // A stale mark that landed meanwhile keeps its own reason and age: it already
                    // queues a rebuild, and its copy may still need clearing when that rebuild fails.
                    $this->updateUnlessMarkedAgain($stale, [
                        'status' => StaleCachedUrl::STATUS_PENDING,
                        'reason' => self::REGENERATE_REASON,
                        'claim_token' => null,
                        'attempts' => 0,
                        'processed_at' => null,
                        'failed_at' => null,
                        'created_at' => CarbonImmutable::now(),
                        'last_error' => 'Cleared: no refresh replaced the stale copy within the maximum stale age; queued to rebuild.',
                    ]);

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

    /**
     * Applies the change only while the row is still the one this batch read: every stale
     * mark resets its status, reason and created_at, so a mark made since is never overwritten.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function updateUnlessMarkedAgain(StaleCachedUrl $stale, array $attributes): bool
    {
        return StaleCachedUrl::query()
            ->whereKey($stale->getKey())
            ->where('status', $stale->status)
            ->where('reason', $stale->reason)
            ->where('created_at', $stale->getRawOriginal('created_at'))
            ->update([...$attributes, 'updated_at' => CarbonImmutable::now()]) === 1;
    }
}
