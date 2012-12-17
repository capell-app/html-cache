<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Core\Models\Page;
use Capell\HtmlCache\Models\CachedModelUrl;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static Collection<int, string> run(array<int, string> $surrogateKeys)
 */
final class ResolveCachedUrlsForSurrogateKeysAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<int, string>  $surrogateKeys
     * @return Collection<int, string>
     */
    public function handle(array $surrogateKeys): Collection
    {
        $urls = collect();
        $pageMorphClass = (new Page)->getMorphClass();

        foreach ($this->pageIds($surrogateKeys) as $pageId) {
            $urls = $urls->merge(ResolveCachedUrlsForModelAction::run($pageMorphClass, $pageId));
        }

        return $urls->merge($this->siteCachedUrls($surrogateKeys)->pluck('url'))->unique()->values();
    }

    /**
     * @param  array<int, string>  $surrogateKeys
     * @return \Illuminate\Database\Eloquent\Collection<int, CachedModelUrl>
     */
    public function siteCachedUrls(array $surrogateKeys): \Illuminate\Database\Eloquent\Collection
    {
        $siteIds = $this->siteIds($surrogateKeys);

        return $siteIds === []
            ? new \Illuminate\Database\Eloquent\Collection
            : CachedModelUrl::query()->whereIn('site_id', $siteIds)->get();
    }

    /**
     * @param  array<int, string>  $surrogateKeys
     * @return list<int>
     */
    public function pageIds(array $surrogateKeys): array
    {
        return array_values(array_unique(array_filter(array_map(
            static function (string $surrogateKey): ?int {
                if (preg_match('/^page-(\d+)$/', $surrogateKey, $matches) !== 1) {
                    return null;
                }

                return (int) $matches[1];
            },
            $surrogateKeys,
        ))));
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
