<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Capell\HtmlCache\Support\Cache\HtmlCachePublicationGuard;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static int run(list<CachedModelUrl|string> $urls)
 */
final class WithdrawCachedUrlsAction
{
    use AsFake;
    use AsObject;

    /**
     * Hard-withdraw these URLs only: fence their in-flight renders, delete
     * their origin artefacts and purge their edge copies in one batch. The
     * site-wide publication generation is left alone, so renders of every
     * other URL still publish.
     *
     * @param  list<CachedModelUrl|string>  $urls
     */
    public function handle(array $urls): int
    {
        $paths = resolve(HtmlCachePathResolver::class);
        $urlKeys = [];

        foreach ($urls as $url) {
            $urlString = $url instanceof CachedModelUrl ? $url->url : $url;

            if ($paths->hasSafeKey($urlString)) {
                $urlKeys[] = HtmlCachePublicationGuard::urlKeyForUrl($urlString);
            }
        }

        // Fence before deleting: a render holding an older token for one of
        // these URLs is refused whether it publishes before or after deletion.
        resolve(HtmlCachePublicationGuard::class)->fence($urlKeys);

        return PurgeEdgeCacheAction::batch(static function () use ($urls): int {
            $withdrawn = 0;

            foreach ($urls as $url) {
                if (ClearCachedUrlAction::run($url, allPorts: true, prefenced: true)) {
                    $withdrawn++;
                }
            }

            return $withdrawn;
        });
    }
}
