<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\HtmlCache\Models\StaleCachedUrl;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static Collection<int, StaleCachedUrl> run(int $limit)
 */
final class SelectStaleHtmlCacheCandidatesAction
{
    use AsFake;
    use AsObject;

    /**
     * @return Collection<int, StaleCachedUrl>
     */
    public function handle(int $limit): Collection
    {
        if ($limit === 1) {
            return $this->nextSingleEligibleStaleUrl();
        }

        $pendingLimit = $limit;

        if ($this->hasRetryableStaleUrls()) {
            $pendingLimit = $limit - $this->retryableShareForBatch($limit);
        }

        $pending = StaleCachedUrl::query()
            ->where('status', StaleCachedUrl::STATUS_PENDING)
            ->oldest()
            ->orderBy('id')
            ->limit($pendingLimit)
            ->get();

        if ($pending->count() >= $limit) {
            return $pending;
        }

        return $pending->merge($this->retryableStaleUrls($limit - $pending->count()));
    }

    private function retryableShareForBatch(int $limit): int
    {
        return max(1, intdiv($limit, 4));
    }

    /** @return Collection<int, StaleCachedUrl> */
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

    /** @return Collection<int, StaleCachedUrl> */
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

        return $failed->merge($this->timedOutProcessingStaleUrlsQuery()
            ->oldest('updated_at')
            ->oldest()
            ->orderBy('id')
            ->limit($limit - $failed->count())
            ->get());
    }

    /** @return Collection<int, StaleCachedUrl> */
    private function nextSingleRetryableStaleUrl(): Collection
    {
        $failed = $this->failedStaleUrlsQuery()->oldest('failed_at')->oldest()->orderBy('id')->limit(1)->get();
        $timedOutProcessing = $this->timedOutProcessingStaleUrlsQuery()->oldest('updated_at')->oldest()->orderBy('id')->limit(1)->get();

        if ($failed->isEmpty()) {
            return $timedOutProcessing;
        }

        if ($timedOutProcessing->isEmpty()) {
            return $failed;
        }

        return $this->shouldPreferTimedOutProcessingForSingleRetryableBatch() ? $timedOutProcessing : $failed;
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
        return $this->failedStaleUrlsQuery()->exists() || $this->timedOutProcessingStaleUrlsQuery()->exists();
    }

    /** @return Builder<StaleCachedUrl> */
    private function failedStaleUrlsQuery(): Builder
    {
        $now = CarbonImmutable::now();

        return StaleCachedUrl::query()
            ->where('status', StaleCachedUrl::STATUS_FAILED)
            ->where('attempts', '<', $this->configuredMaxAttempts())
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('failed_at')
                    ->orWhere('failed_at', '<=', $now->subMinutes($this->retryBackoffMinutes()));
            });
    }

    /** @return Builder<StaleCachedUrl> */
    private function timedOutProcessingStaleUrlsQuery(): Builder
    {
        return StaleCachedUrl::query()
            ->where('status', StaleCachedUrl::STATUS_PROCESSING)
            ->where('updated_at', '<=', CarbonImmutable::now()->subMinutes($this->configuredProcessingTimeoutMinutes()));
    }

    private function configuredProcessingTimeoutMinutes(): int
    {
        $value = config('capell-html-cache.invalidation.processing_timeout_minutes', 15);

        return is_numeric($value) ? max(1, (int) $value) : 15;
    }

    private function retryBackoffMinutes(): int
    {
        $value = config('capell-html-cache.invalidation.retry_backoff_minutes', 5);

        return is_numeric($value) ? max(1, (int) $value) : 5;
    }

    private function configuredMaxAttempts(): int
    {
        $value = config('capell-html-cache.invalidation.max_attempts', 5);

        return is_numeric($value) ? max(1, (int) $value) : 5;
    }
}
