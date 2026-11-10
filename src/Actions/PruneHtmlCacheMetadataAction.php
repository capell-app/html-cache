<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\HtmlCache\Models\HtmlCacheGenerationRun;
use Capell\HtmlCache\Models\HtmlCacheModelWithdrawal;
use Capell\HtmlCache\Models\StaleCachedUrl;
use Carbon\CarbonImmutable;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** @method static int run() */
final class PruneHtmlCacheMetadataAction
{
    use AsFake;
    use AsObject;

    /**
     * A withdrawal only matters to renders still being published or indexed;
     * a day covers delayed queues and retries.
     */
    private const int MODEL_WITHDRAWAL_RETENTION_HOURS = 24;

    public function handle(): int
    {
        $staleRetentionDays = $this->retentionDays('capell-html-cache.retention.processed_stale_days', 7);
        $generationRetentionDays = $this->retentionDays('capell-html-cache.retention.generation_run_days', 30);

        $staleDeleted = StaleCachedUrl::query()
            ->where('status', StaleCachedUrl::STATUS_PROCESSED)
            ->where('processed_at', '<', CarbonImmutable::now()->subDays($staleRetentionDays))
            ->delete();

        $runsDeleted = HtmlCacheGenerationRun::query()
            ->whereIn('status', [HtmlCacheGenerationRun::STATUS_COMPLETED, HtmlCacheGenerationRun::STATUS_FAILED])
            ->where('finished_at', '<', CarbonImmutable::now()->subDays($generationRetentionDays))
            ->delete();

        $withdrawalsDeleted = HtmlCacheModelWithdrawal::query()
            ->where('withdrawn_at', '<', CarbonImmutable::now()->subHours(self::MODEL_WITHDRAWAL_RETENTION_HOURS))
            ->delete();

        return (is_int($staleDeleted) ? $staleDeleted : 0)
            + (is_int($runsDeleted) ? $runsDeleted : 0)
            + (is_int($withdrawalsDeleted) ? $withdrawalsDeleted : 0);
    }

    private function retentionDays(string $key, int $default): int
    {
        $configured = config($key, $default);

        return is_numeric($configured) ? max(1, (int) $configured) : $default;
    }
}
