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
        $batchSize = $limit ?? $this->configuredBatchSize(config('capell-html-cache.invalidation.batch_size', 100));
        $processed = 0;

        StaleCachedUrl::query()
            ->where('status', StaleCachedUrl::STATUS_PENDING)
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(max(1, $batchSize))
            ->get()
            ->each(function (StaleCachedUrl $staleCachedUrl) use (&$processed): void {
                $this->processStaleCachedUrl($staleCachedUrl);
                $processed++;
            });

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

    private function processStaleCachedUrl(StaleCachedUrl $staleCachedUrl): void
    {
        $staleCachedUrl->forceFill([
            'status' => StaleCachedUrl::STATUS_PROCESSING,
            'attempts' => $staleCachedUrl->attempts + 1,
            'failed_at' => null,
            'last_error' => null,
        ])->save();

        try {
            RefreshCachedUrlAtomicallyAction::run($staleCachedUrl);

            $staleCachedUrl->forceFill([
                'status' => StaleCachedUrl::STATUS_PROCESSED,
                'processed_at' => CarbonImmutable::now(),
                'failed_at' => null,
                'last_error' => null,
            ])->save();
        } catch (Throwable $throwable) {
            $staleCachedUrl->forceFill([
                'status' => StaleCachedUrl::STATUS_FAILED,
                'failed_at' => CarbonImmutable::now(),
                'last_error' => Str::limit($throwable->getMessage(), 2000, ''),
            ])->save();
        }
    }
}
