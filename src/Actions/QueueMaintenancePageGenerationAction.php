<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Core\Models\Site;
use Capell\HtmlCache\Jobs\GenerateMaintenancePagesJob;
use Capell\HtmlCache\Models\HtmlCacheGenerationRun;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

/** @method static HtmlCacheGenerationRun run(Collection<int, Site> $sites, bool $enableGlobal = false, ?string $secret = null, ?int $activateSiteId = null) */
final class QueueMaintenancePageGenerationAction
{
    use AsFake;
    use AsObject;

    /** @param Collection<int, Site> $sites */
    public function handle(Collection $sites, bool $enableGlobal = false, ?string $secret = null, ?int $activateSiteId = null): HtmlCacheGenerationRun
    {
        $siteIds = $sites
            ->map(static function (Site $site): int {
                $siteId = $site->getKey();
                throw_unless(is_int($siteId), RuntimeException::class, 'Site must have an integer key.');

                return $siteId;
            })
            ->values()
            ->all();

        $run = Cache::lock('capell-html-cache:maintenance-generation:start', 10)->get(function () use ($siteIds): HtmlCacheGenerationRun {
            throw_if(
                HtmlCacheGenerationRun::query()->whereIn('status', [
                    HtmlCacheGenerationRun::STATUS_PENDING,
                    HtmlCacheGenerationRun::STATUS_RUNNING,
                ])->exists(),
                RuntimeException::class,
                'A cache generation run is already active.',
            );

            return HtmlCacheGenerationRun::query()->create([
                'status' => HtmlCacheGenerationRun::STATUS_PENDING,
                'total_sites' => count($siteIds),
                'completed_sites' => 0,
                'failed_sites' => 0,
            ]);
        });

        throw_unless($run instanceof HtmlCacheGenerationRun, RuntimeException::class, 'A cache generation run is already being queued.');

        $runId = $run->getKey();
        throw_unless(is_int($runId) || is_string($runId), RuntimeException::class, 'Cache generation run must have a scalar key.');

        GenerateMaintenancePagesJob::dispatch(
            runId: (string) $runId,
            siteIds: array_values($siteIds),
            enableGlobal: $enableGlobal,
            secret: $secret,
            activateSiteId: $activateSiteId,
        )->afterCommit();

        return $run;
    }
}
