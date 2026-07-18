<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Core\Models\Site;
use Capell\HtmlCache\Enums\HtmlCacheKey;
use Capell\HtmlCache\Models\HtmlCacheGenerationRun;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

/**
 * @method static HtmlCacheGenerationRun run(Collection<int, Site> $sites)
 */
final class GenerateStaticSitesAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  Collection<int, Site>  $sites
     */
    public function handle(Collection $sites): HtmlCacheGenerationRun
    {
        PruneHtmlCacheMetadataAction::run();

        $run = Cache::lock('capell-html-cache:static-generation:start', 10)->block(5, function () use ($sites): HtmlCacheGenerationRun {
            throw_if(
                HtmlCacheGenerationRun::query()->where('status', HtmlCacheGenerationRun::STATUS_RUNNING)->exists(),
                RuntimeException::class,
                'A static HTML generation run is already active.',
            );

            return HtmlCacheGenerationRun::query()->create([
                'status' => $sites->isEmpty() ? HtmlCacheGenerationRun::STATUS_COMPLETED : HtmlCacheGenerationRun::STATUS_RUNNING,
                'total_sites' => $sites->count(),
                'started_at' => now(),
                'finished_at' => $sites->isEmpty() ? now() : null,
            ]);
        });

        throw_unless($run instanceof HtmlCacheGenerationRun, RuntimeException::class, 'Unable to start the static HTML generation run.');

        if ($sites->isEmpty()) {
            Cache::forget(HtmlCacheKey::GeneratingStaticSite->value);

            return $run;
        }

        Cache::put(HtmlCacheKey::GeneratingStaticSite->value, $run->id, now()->addDay());

        foreach ($sites as $site) {
            GenerateStaticSiteAction::dispatch($site, $run->id);
        }

        return $run;
    }
}
