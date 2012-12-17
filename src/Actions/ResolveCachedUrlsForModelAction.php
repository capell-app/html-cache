<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\HtmlCache\Models\CachedModelUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use UnexpectedValueException;

/**
 * @method static Collection<int, string> run(Model|string $model, int|string|null $modelKey = null, bool $includePageUrls = true)
 */
final class ResolveCachedUrlsForModelAction
{
    use AsFake;
    use AsObject;

    /**
     * Resolve tracked URLs without changing cache state. Page URLs also cover
     * uncaptured entries, including cached draft-page 404 responses.
     *
     * @return Collection<int, string>
     */
    public function handle(Model|string $model, int|string|null $modelKey = null, bool $includePageUrls = true): Collection
    {
        $morphClass = $model instanceof Model ? $model->getMorphClass() : $model;
        $key = $model instanceof Model ? $model->getKey() : $modelKey;

        if ($key !== null && ! is_int($key) && ! is_string($key)) {
            throw new UnexpectedValueException('Cacheable model keys must be integers or strings.');
        }

        $key = (int) $key;

        $urls = CachedModelUrl::query()
            ->where('cacheable_type', $morphClass)
            ->where('cacheable_id', $key)
            ->pluck('url')
            ->filter(static fn (mixed $url): bool => is_string($url))
            ->unique()
            ->values();

        if ($includePageUrls && $morphClass === (new Page)->getMorphClass()) {
            $pageUrls = PageUrl::query()
                ->where('pageable_type', $morphClass)
                ->where('pageable_id', $key)
                ->get()
                ->map(fn (PageUrl $pageUrl): string => $pageUrl->fullUrl());

            $urls = $urls
                ->merge($pageUrls)
                ->unique()
                ->values();
        }

        return $urls;
    }
}
