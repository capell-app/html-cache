<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Models\StaleCachedUrl;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsJob;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * @method static bool run(string $url)
 */
final class RefreshOriginStaleCachedUrlAction
{
    use AsJob;
    use AsObject;

    public function handle(string $url): bool
    {
        $staleCachedUrl = $this->eligibleStaleCachedUrl($url);

        if (! $staleCachedUrl instanceof StaleCachedUrl) {
            return false;
        }

        if (! $this->claimStaleCachedUrl($staleCachedUrl)) {
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

    private function eligibleStaleCachedUrl(string $url): ?StaleCachedUrl
    {
        return $this->eligibleStaleUrlsQuery()
            ->where('url_hash', CachedModelUrl::hashUrl($url))
            ->where(function (Builder $query): void {
                $query
                    ->whereNotNull('cache_path')
                    ->orWhereNotNull('error_cache_path');
            })
            ->oldest()
            ->first();
    }

    private function claimStaleCachedUrl(StaleCachedUrl $staleCachedUrl): bool
    {
        $claimToken = (string) Str::uuid();

        return $this->eligibleStaleUrlsQuery()
            ->whereKey($staleCachedUrl->getKey())
            ->update([
                'status' => StaleCachedUrl::STATUS_PROCESSING,
                'claim_token' => $claimToken,
                'attempts' => DB::raw('attempts + 1'),
                'failed_at' => null,
                'last_error' => null,
                'updated_at' => CarbonImmutable::now(),
            ]) === 1;
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

    /**
     * @return Builder<StaleCachedUrl>
     */
    private function eligibleStaleUrlsQuery(): Builder
    {
        $now = CarbonImmutable::now();
        $processingTimeoutAt = $now->subMinutes($this->configuredProcessingTimeoutMinutes());

        return StaleCachedUrl::query()
            ->where(function (Builder $query) use ($now, $processingTimeoutAt): void {
                $query
                    ->where('status', StaleCachedUrl::STATUS_PENDING)
                    ->orWhere(function (Builder $query) use ($now): void {
                        $query
                            ->where('status', StaleCachedUrl::STATUS_FAILED)
                            ->where('attempts', '<', $this->configuredMaxAttempts())
                            ->where(function (Builder $query) use ($now): void {
                                $query
                                    ->whereNull('failed_at')
                                    ->orWhere('failed_at', '<=', $now->subMinutes($this->retryBackoffMinutes()));
                            });
                    })
                    ->orWhere(function (Builder $query) use ($processingTimeoutAt): void {
                        $query
                            ->where('status', StaleCachedUrl::STATUS_PROCESSING)
                            ->where('updated_at', '<=', $processingTimeoutAt);
                    });
            });
    }

    private function configuredProcessingTimeoutMinutes(): int
    {
        $configuredTimeout = config('capell-html-cache.invalidation.processing_timeout_minutes', 15);

        return is_numeric($configuredTimeout) ? max(1, (int) $configuredTimeout) : 15;
    }

    private function retryBackoffMinutes(): int
    {
        $configuredBackoff = config('capell-html-cache.invalidation.retry_backoff_minutes', 5);

        return is_numeric($configuredBackoff) ? max(1, (int) $configuredBackoff) : 5;
    }

    private function configuredMaxAttempts(): int
    {
        $configuredMaxAttempts = config('capell-html-cache.invalidation.max_attempts', 5);

        return is_numeric($configuredMaxAttempts) ? max(1, (int) $configuredMaxAttempts) : 5;
    }
}
