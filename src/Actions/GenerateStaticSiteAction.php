<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Core\Models\Site;
use Capell\HtmlCache\Enums\HtmlCacheKey;
use Capell\HtmlCache\Models\HtmlCacheGenerationRun;
use Capell\HtmlCache\Support\StaticSite\StaticSiteGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsJob;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * @method static void run(Site $site, string|null $cacheKey = null)
 * @method static void dispatch(Site $site, string|null $cacheKey = null)
 */
final class GenerateStaticSiteAction implements ShouldBeUnique
{
    use AsFake;
    use AsJob;
    use AsObject;

    public function handle(Site $site, string $cacheKey = HtmlCacheKey::GeneratingStaticSite->value): void
    {
        try {
            (new StaticSiteGenerator($site))->process();
            $this->completeRun($cacheKey, $site, failed: false);
        } catch (Throwable $throwable) {
            $this->completeRun($cacheKey, $site, failed: true, error: $throwable->getMessage());

            throw $throwable;
        }
    }

    public function getJobUniqueId(Site $site, string $cacheKey = HtmlCacheKey::GeneratingStaticSite->value): string
    {
        return sprintf('html-cache-generation-%s-site-%s', $cacheKey, $this->siteKey($site));
    }

    private function completeRun(string $runId, Site $site, bool $failed, ?string $error = null): void
    {
        if ($runId === HtmlCacheKey::GeneratingStaticSite->value) {
            Cache::forget($runId);

            return;
        }

        DB::transaction(function () use ($runId, $site, $failed, $error): void {
            $run = HtmlCacheGenerationRun::query()->lockForUpdate()->find($runId);

            if (! $run instanceof HtmlCacheGenerationRun || in_array($run->status, [HtmlCacheGenerationRun::STATUS_COMPLETED, HtmlCacheGenerationRun::STATUS_FAILED], true)) {
                return;
            }

            if ($failed) {
                $errors = is_array($run->errors) ? $run->errors : [];
                $errors[$this->siteKey($site)] = $error;
                $run->failed_sites++;
                $run->errors = $errors;
            } else {
                $run->completed_sites++;
            }

            if ($run->completed_sites + $run->failed_sites >= $run->total_sites) {
                $run->status = $run->failed_sites > 0
                    ? HtmlCacheGenerationRun::STATUS_FAILED
                    : HtmlCacheGenerationRun::STATUS_COMPLETED;
                $run->finished_at = CarbonImmutable::now();
                Cache::forget(HtmlCacheKey::GeneratingStaticSite->value);
            }

            $run->save();
        }, attempts: 5);
    }

    private function siteKey(Site $site): string
    {
        $key = $site->getKey();

        return is_int($key) || is_string($key) ? (string) $key : 'unsaved';
    }
}
