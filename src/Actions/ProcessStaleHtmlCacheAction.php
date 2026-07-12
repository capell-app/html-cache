<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\HtmlCache\Models\StaleCachedUrl;
use Carbon\CarbonImmutable;
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
            $candidates = SelectStaleHtmlCacheCandidatesAction::run($batchSize - $processed);

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
