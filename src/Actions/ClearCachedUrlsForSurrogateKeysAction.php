<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\HtmlCache\Models\CachedModelUrl;
use Illuminate\Database\Eloquent\Collection;
use Lorisleiva\Actions\Concerns\AsJob;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static int run(array<int, string> $surrogateKeys)
 */
final class ClearCachedUrlsForSurrogateKeysAction
{
    use AsJob;
    use AsObject;

    public bool $jobDeleteWhenMissingModels = true;

    /**
     * @param  array<int, string>  $surrogateKeys
     */
    public function handle(array $surrogateKeys): int
    {
        $siteIds = $this->siteIds($surrogateKeys);

        if ($siteIds === []) {
            return 0;
        }

        /** @var Collection<int, CachedModelUrl> $cachedUrls */
        $cachedUrls = CachedModelUrl::query()
            ->whereIn('site_id', $siteIds)
            ->get();

        $cleared = 0;

        foreach ($cachedUrls as $cachedUrl) {
            if (ClearCachedUrlAction::run($cachedUrl)) {
                $cleared++;
            }
        }

        return $cleared;
    }

    /**
     * @param  array<int, string>  $surrogateKeys
     * @return list<int>
     */
    private function siteIds(array $surrogateKeys): array
    {
        return array_values(array_unique(array_filter(array_map(
            static function (string $surrogateKey): ?int {
                if (preg_match('/^site-(\d+)$/', $surrogateKey, $matches) !== 1) {
                    return null;
                }

                return (int) $matches[1];
            },
            $surrogateKeys,
        ))));
    }
}
