<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\HtmlCache\Data\StaleHtmlCacheProcessResultData;
use Capell\HtmlCache\Exceptions\StaleCachedUrlNotApplicableException;
use Capell\HtmlCache\Models\StaleCachedUrl;
use Capell\HtmlCache\Support\Cache\OriginRefreshLock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsJob;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Throwable;

/**
 * @method static StaleHtmlCacheProcessResultData run(?int $limit = null, bool $suppressInlineEdgePurge = false)
 */
final class ProcessStaleHtmlCacheAction
{
    use AsFake;
    use AsJob;
    use AsObject;

    public function asJob(?int $limit = null, bool $suppressInlineEdgePurge = false): void
    {
        $result = $this->handle($limit, $suppressInlineEdgePurge);

        if ($result->leaseLost) {
            throw new RuntimeException(sprintf(
                'The origin refresh lock was lost or reached its hard cap after %d HTML cache URLs; the refresh stopped so it cannot render alongside other refreshes.',
                $result->attempted,
            ));
        }

        if (! $result->successful()) {
            throw new RuntimeException(sprintf(
                'Failed to refresh %d of %d attempted HTML cache URLs.',
                $result->failed,
                $result->attempted,
            ));
        }
    }

    public function handle(?int $limit = null, bool $suppressInlineEdgePurge = false): StaleHtmlCacheProcessResultData
    {
        PruneHtmlCacheMetadataAction::run();

        $batchSize = max(1, $limit ?? $this->configuredBatchSize(config('capell-html-cache.invalidation.batch_size', 100)));
        $result = new StaleHtmlCacheProcessResultData;
        $emptyPasses = 0;
        $deferredIds = [];

        while ($result->attempted < $batchSize && $emptyPasses < 2) {
            $candidates = SelectStaleHtmlCacheCandidatesAction::run($batchSize - $result->attempted);

            if ($candidates->isEmpty()) {
                break;
            }

            $attemptedBefore = $result->attempted;

            foreach ($candidates as $staleCachedUrl) {
                if (isset($deferredIds[$staleCachedUrl->id])) {
                    continue;
                }

                // A caller that holds the origin refresh lock keeps it alive while it
                // works through a long list, and stops the moment it no longer owns it.
                // Any other caller must be admitted for each render like a queued job.
                $lock = resolve(OriginRefreshLock::class);
                $renewed = $lock->renewOwned();

                if ($renewed === false) {
                    $result->leaseLost = true;

                    break 2;
                }

                $admission = null;

                if ($renewed === null) {
                    $admission = 'process:' . Str::uuid();

                    if (! $lock->tryAcquireSlot($admission, $this->admissionTtlSeconds())) {
                        // Busy now; later URLs may find a free slot, and this one stays pending.
                        $deferredIds[$staleCachedUrl->id] = true;
                        $result->deferred++;

                        continue;
                    }
                }

                try {
                    if (! ClaimStaleCachedUrlAction::run($staleCachedUrl)) {
                        $deferredIds[$staleCachedUrl->id] = true;
                        $result->deferred++;

                        continue;
                    }

                    $result->attempted++;
                    $this->processStaleCachedUrl($staleCachedUrl, $suppressInlineEdgePurge, $result);
                } finally {
                    if ($admission !== null) {
                        $lock->release($admission);
                    }
                }
            }

            if ($result->attempted === $attemptedBefore) {
                $emptyPasses++;
            }
        }

        return $result;
    }

    /**
     * A render is one synchronous call that cannot be renewed part-way, so the slot is short:
     * a process killed mid-render must not hold it for the whole claim timeout and starve
     * expiry, which waits longer than this.
     */
    private function admissionTtlSeconds(): int
    {
        return OriginRefreshLock::MAX_SLOT_TTL_SECONDS;
    }

    private function configuredBatchSize(mixed $configuredLimit): int
    {
        if (is_int($configuredLimit)) {
            return $configuredLimit;
        }

        if (is_numeric($configuredLimit)) {
            return (int) $configuredLimit;
        }

        return 100;
    }

    private function configuredMaxAttempts(): int
    {
        $configuredMaxAttempts = config('capell-html-cache.invalidation.max_attempts', 5);

        if (is_int($configuredMaxAttempts)) {
            return max(1, $configuredMaxAttempts);
        }

        if (is_numeric($configuredMaxAttempts)) {
            return max(1, (int) $configuredMaxAttempts);
        }

        return 5;
    }

    private function processStaleCachedUrl(StaleCachedUrl $staleCachedUrl, bool $suppressInlineEdgePurge, StaleHtmlCacheProcessResultData $result): void
    {
        $staleCachedUrl->refresh();

        try {
            RefreshCachedUrlAtomicallyAction::run($staleCachedUrl, $suppressInlineEdgePurge);

            $completed = $this->completeClaim($staleCachedUrl, [
                'status' => StaleCachedUrl::STATUS_PROCESSED,
                'claim_token' => null,
                'attempts' => 0,
                'processed_at' => CarbonImmutable::now(),
                'failed_at' => null,
                'last_error' => null,
            ]);

            $completed ? $result->succeeded++ : $result->deferred++;
        } catch (StaleCachedUrlNotApplicableException $exception) {
            $completed = $this->completeClaim($staleCachedUrl, [
                'status' => StaleCachedUrl::STATUS_NOT_APPLICABLE,
                'claim_token' => null,
                'failed_at' => null,
                'last_error' => Str::limit($exception->getMessage(), 2000, ''),
            ]);

            $completed ? $result->notApplicable++ : $result->deferred++;
        } catch (Throwable $throwable) {
            $result->failed++;
            $this->completeClaim($staleCachedUrl, [
                'status' => $staleCachedUrl->attempts >= $this->configuredMaxAttempts()
                    ? StaleCachedUrl::STATUS_EXHAUSTED
                    : StaleCachedUrl::STATUS_FAILED,
                'claim_token' => null,
                'failed_at' => CarbonImmutable::now(),
                'last_error' => Str::limit($throwable->getMessage(), 2000, ''),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function completeClaim(StaleCachedUrl $staleCachedUrl, array $attributes): bool
    {
        $claimToken = $staleCachedUrl->claim_token;

        if (! is_string($claimToken) || $claimToken === '') {
            return false;
        }

        return StaleCachedUrl::query()
            ->whereKey($staleCachedUrl->getKey())
            ->where('status', StaleCachedUrl::STATUS_PROCESSING)
            ->where('claim_token', $claimToken)
            ->update([
                ...$attributes,
                'updated_at' => CarbonImmutable::now(),
            ]) === 1;
    }
}
