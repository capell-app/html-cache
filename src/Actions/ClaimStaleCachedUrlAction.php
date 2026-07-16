<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\HtmlCache\Models\StaleCachedUrl;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static bool run(StaleCachedUrl $staleCachedUrl)
 */
final class ClaimStaleCachedUrlAction
{
    use AsFake;
    use AsObject;

    public function handle(StaleCachedUrl $staleCachedUrl): bool
    {
        $claimToken = (string) Str::uuid();

        $updated = $this->eligibleStaleUrlsQuery()
            ->whereKey($staleCachedUrl->getKey())
            ->update([
                'status' => StaleCachedUrl::STATUS_PROCESSING,
                'claim_token' => $claimToken,
                'attempts' => DB::raw('attempts + 1'),
                'failed_at' => null,
                'last_error' => null,
                'updated_at' => CarbonImmutable::now(),
            ]);

        return $updated === 1;
    }

    /** @return Builder<StaleCachedUrl> */
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

        if (is_int($configuredTimeout)) {
            return max(1, $configuredTimeout);
        }

        return is_numeric($configuredTimeout) ? max(1, (int) $configuredTimeout) : 15;
    }

    private function retryBackoffMinutes(): int
    {
        $configuredBackoff = config('capell-html-cache.invalidation.retry_backoff_minutes', 5);

        if (is_int($configuredBackoff)) {
            return max(1, $configuredBackoff);
        }

        return is_numeric($configuredBackoff) ? max(1, (int) $configuredBackoff) : 5;
    }

    private function configuredMaxAttempts(): int
    {
        $configuredMaxAttempts = config('capell-html-cache.invalidation.max_attempts', 5);

        if (is_int($configuredMaxAttempts)) {
            return max(1, $configuredMaxAttempts);
        }

        return is_numeric($configuredMaxAttempts) ? max(1, (int) $configuredMaxAttempts) : 5;
    }
}
