<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Core\Models\Page;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsJob;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static int run(array<int, string> $surrogateKeys)
 */
final class ClearCachedUrlsForSurrogateKeysAction
{
    use AsFake;
    use AsJob;
    use AsObject;

    public bool $jobDeleteWhenMissingModels = true;

    /**
     * @param  array<int, string>  $surrogateKeys
     */
    public function handle(array $surrogateKeys): int
    {
        $cleared = 0;
        $resolver = resolve(ResolveCachedUrlsForSurrogateKeysAction::class);

        $pageMorphClass = (new Page)->getMorphClass();

        foreach ($resolver->pageIds($surrogateKeys) as $pageId) {
            $cleared += ClearCachedUrlsForModelAction::run($pageMorphClass, $pageId);
        }

        $cachedUrls = $resolver->siteCachedUrls($surrogateKeys);

        foreach ($cachedUrls as $cachedUrl) {
            if (ClearCachedUrlAction::run($cachedUrl)) {
                $cleared++;
            }
        }

        return $cleared;
    }
}
