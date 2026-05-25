<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Core\Models\Site;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsJob;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static int run(Collection<int, string> $urls, ?EloquentCollection<int, Site> $sites = null)
 */
final class ClearCachedPageUrlsAction
{
    use AsFake;
    use AsJob;
    use AsObject;

    /**
     * @param  Collection<int, string>  $urls
     */
    public function handle(Collection $urls): int
    {
        $cleared = 0;

        foreach ($urls as $url) {
            if (is_string($url) && ClearCachedUrlAction::run($url)) {
                $cleared++;
            }
        }

        return $cleared;
    }
}
