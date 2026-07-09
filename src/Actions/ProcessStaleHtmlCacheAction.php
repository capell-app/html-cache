<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\HtmlCache\Models\StaleCachedUrl;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsJob;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * @method static int run(?int $limit = null)
 */
final class ProcessStaleHtmlCacheAction
{
    use AsJob;
    use AsObject;

    public function handle(?int $limit = null): int
    {
        $batchSize = max(1, $limit ?? $this->configuredBatchSize(config('capell-html-cache.invalidation.batch_size', 100)));
        $processed = 0;
        $emptyPasses = 0;

        while ($processed < $batchSize && $emptyPasses < 2) {
            $candidates = $this->nextEligibleStaleUrls($batchSize - $processed);

            if ($candidates->isEmpty()) {
                break;
            }

            $processedThisPass = 0;

            $candidates->each(function (StaleCachedUrl $staleCachedUrl) use (&$processed, &$processedThisPass): void {
                if ($this->processStaleCachedUrl($staleCachedUrl)) {
                    $processed++;
                    $processedThisPass++;
                }
            });

            if ($processedThisPass === 0) {
                $emptyPasses++;
            }
        }

        return $processed;
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

    /**
     * @return Collection<int, StaleCachedUrl>
     */
    private function nextEligibleStaleUrls(int $limit): Collection
    {
        if ($limit === 1) {
            return $this->nextSingleEligibleStaleUrl();
        }

        $pendingLimit = $limit;

        if ($this->hasRetryableStaleUrls()) {
            $pendingLimit = $limit - $this->retryableShareForBatch($limit);
        }

        $pending = StaleCachedUrl::query()
            ->where('status', StaleCachedUrl::STATUS_PENDING)->oldest()
            ->orderBy('id')
            ->limit($pendingLimit)
            ->get();

        if ($pending->count() >= $limit) {
            return $pending;
        }

        $retryable = $this->retryableStaleUrls($limit - $pending->count());

        return $pending->merge($retryable);
    }

    private function retryableShareForBatch(int $limit): int
    {
        return max(1, intdiv($limit, 4));
    }

    /**
     * @return Collection<int, StaleCachedUrl>
     */
    private function nextSingleEligibleStaleUrl(): Collection
    {
        $pendingQuery = StaleCachedUrl::query()
            ->where('status', StaleCachedUrl::STATUS_PENDING)
            ->oldest()
            ->orderBy('id');
        $hasPending = $pendingQuery->exists();
        $hasRetryable = $this->hasRetryableStaleUrls();

        if (! $hasPending) {
            return $this->retryableStaleUrls(1);
        }

        if (! $hasRetryable) {
            return $pendingQuery->limit(1)->get();
        }

        if ($this->shouldPreferRetryableForSingleItemBatch()) {
            $retryable = $this->retryableStaleUrls(1);

            return $retryable->isNotEmpty() ? $retryable : $pendingQuery->limit(1)->get();
        }

        $pending = $pendingQuery->limit(1)->get();

        return $pending->isNotEmpty() ? $pending : $this->retryableStaleUrls(1);
    }

    private function shouldPreferRetryableForSingleItemBatch(): bool
    {
        $cacheKey = 'capell-html-cache:stale-refresh:single-item-source';
        $nextSource = Cache::get($cacheKey, 'pending');

        Cache::put($cacheKey, $nextSource === 'retryable' ? 'pending' : 'retryable', now()->addDay());

        return $nextSource === 'retryable';
    }

    /**
     * @return Collection<int, StaleCachedUrl>
     */
    private function retryableStaleUrls(int $limit): Collection
    {
        if ($limit <= 0) {
            return new Collection;
        }

        if ($limit === 1) {
            return $this->nextSingleRetryableStaleUrl();
        }

        $failedLimit = $limit;

        if ($this->timedOutProcessingStaleUrlsQuery()->exists()) {
            $failedLimit = $limit - 1;
        }

        $failed = $this->failedStaleUrlsQuery()
            ->oldest('failed_at')
            ->oldest()
            ->orderBy('id')
            ->limit($failedLimit)
            ->get();

        if ($failed->count() >= $limit) {
            return $failed;
        }

        $timedOutProcessing = $this->timedOutProcessingStaleUrlsQuery()
            ->oldest('updated_at')
            ->oldest()
            ->orderBy('id')
            ->limit($limit - $failed->count())
            ->get();

        return $failed->merge($timedOutProcessing);
    }

    /**
     * @return Collection<int, StaleCachedUrl>
     */
    private function nextSingleRetryableStaleUrl(): Collection
    {
        $failed = $this->failedStaleUrlsQuery()
            ->oldest('failed_at')
            ->oldest()
            ->orderBy('id')
            ->limit(1)
            ->get();
        $timedOutProcessing = $this->timedOutProcessingStaleUrlsQuery()
            ->oldest('updated_at')
            ->oldest()
            ->orderBy('id')
            ->limit(1)
            ->get();

        if ($failed->isEmpty()) {
            return $timedOutProcessing;
        }

        if ($timedOutProcessing->isEmpty()) {
            return $failed;
        }

        if ($this->shouldPreferTimedOutProcessingForSingleRetryableBatch()) {
            return $timedOutProcessing;
        }

        return $failed;
    }

    private function shouldPreferTimedOutProcessingForSingleRetryableBatch(): bool
    {
        $cacheKey = 'capell-html-cache:stale-refresh:single-retryable-source';
        $nextSource = Cache::get($cacheKey, 'failed');

        Cache::put($cacheKey, $nextSource === 'timed_out_processing' ? 'failed' : 'timed_out_processing', now()->addDay());

        return $nextSource === 'timed_out_processing';
    }

    private function hasRetryableStaleUrls(): bool
    {
        if ($this->failedStaleUrlsQuery()->exists()) {
            return true;
        }

        return $this->timedOutProcessingStaleUrlsQuery()->exists();
    }

    /**
     * @return Builder<StaleCachedUrl>
     */
    private function failedStaleUrlsQuery(): Builder
    {
        $now = CarbonImmutable::now();

        return StaleCachedUrl::query()
            ->where('status', StaleCachedUrl::STATUS_FAILED)
            ->where('attempts', '<', $this->configuredMaxAttempts())
            ->where(function (Builder $query) use ($now): void {
                $query
                    ->whereNull('failed_at')
                    ->orWhere('failed_at', '<=', $now->subMinutes($this->retryBackoffMinutes()));
            });
    }

    /**
     * @return Builder<StaleCachedUrl>
     */
    private function timedOutProcessingStaleUrlsQuery(): Builder
    {
        return StaleCachedUrl::query()
            ->where('status', StaleCachedUrl::STATUS_PROCESSING)
            ->where('updated_at', '<=', CarbonImmutable::now()->subMinutes($this->configuredProcessingTimeoutMinutes()));
    }

    private function configuredProcessingTimeoutMinutes(): int
    {
        $configuredTimeout = config('capell-html-cache.invalidation.processing_timeout_minutes', 15);

        if (is_int($configuredTimeout)) {
            return max(1, $configuredTimeout);
        }

        if (is_numeric($configuredTimeout)) {
            return max(1, (int) $configuredTimeout);
        }

        return 15;
    }

    private function retryBackoffMinutes(): int
    {
        $configuredBackoff = config('capell-html-cache.invalidation.retry_backoff_minutes', 5);

        if (is_int($configuredBackoff)) {
            return max(1, $configuredBackoff);
        }

        if (is_numeric($configuredBackoff)) {
            return max(1, (int) $configuredBackoff);
        }

        return 5;
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

    private function processStaleCachedUrl(StaleCachedUrl $staleCachedUrl): bool
    {
        if (! ClaimStaleCachedUrlAction::run($staleCachedUrl)) {
            return false;
        }

        $staleCachedUrl->refresh();

        try {
            RefreshCachedUrlAtomicallyAction::run($staleCachedUrl);

            $this->completeClaim($staleCachedUrl, [
                'status' => StaleCachedUrl::STATUS_PROCESSED,
                'claim_token' => null,
                'attempts' => 0,
                'processed_at' => CarbonImmutable::now(),
                'failed_at' => null,
                'last_error' => null,
            ]);
        } catch (Throwable $throwable) {
            $this->completeClaim($staleCachedUrl, [
                'status' => $staleCachedUrl->attempts >= $this->configuredMaxAttempts()
                    ? StaleCachedUrl::STATUS_EXHAUSTED
                    : StaleCachedUrl::STATUS_FAILED,
                'claim_token' => null,
                'failed_at' => CarbonImmutable::now(),
                'last_error' => Str::limit($throwable->getMessage(), 2000, ''),
            ]);
        }

        return true;
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
