<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Core\Models\Site;
use Capell\HtmlCache\Enums\HtmlCacheKey;
use Capell\HtmlCache\Support\StaticSite\StaticSiteGenerator;
use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsJob;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static void run(Site $site, string|null $cacheKey = null)
 * @method static void dispatch(Site $site, string|null $cacheKey = null)
 */
final class GenerateStaticSiteAction
{
    use AsFake;
    use AsJob;
    use AsObject;

    private string $cacheKey;

    public function handle(Site $site, string $cacheKey = HtmlCacheKey::GeneratingStaticSite->value): void
    {
        $this->cacheKey = $cacheKey;

        try {
            (new StaticSiteGenerator($site))->process();
        } finally {
            $this->updateCache();
        }
    }

    private function updateCache(): void
    {
        $remaining = Cache::get($this->cacheKey, 0) - 1;

        if ($remaining <= 0) {
            Cache::forget($this->cacheKey);
        } else {
            Cache::decrement($this->cacheKey);
        }
    }
}
