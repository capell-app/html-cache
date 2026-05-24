<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Core\Models\Site;
use Capell\HtmlCache\Enums\HtmlCacheKey;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static void run(Collection<int, \Capell\Core\Models\Site> $sites)
 */
final class GenerateStaticSitesAction
{
    use AsObject;

    private string $cacheKey = HtmlCacheKey::GeneratingStaticSite->value;

    /**
     * @param  Collection<int, Site>  $sites
     */
    public function handle(Collection $sites): void
    {
        Cache::put($this->cacheKey, $sites->count(), now()->addMinutes(20));

        foreach ($sites as $site) {
            GenerateStaticSiteAction::dispatch($site, $this->cacheKey);
        }
    }
}
