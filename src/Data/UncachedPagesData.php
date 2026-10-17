<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Data;

use Spatie\LaravelData\Data;

final class UncachedPagesData extends Data
{
    /** @var list<UncachedPageData> */
    public readonly array $pages;

    /** @param list<UncachedPageData> $pages */
    public function __construct(array $pages)
    {
        usort($pages, static function (UncachedPageData $left, UncachedPageData $right): int {
            if ($left->lastRenderMilliseconds === null || $right->lastRenderMilliseconds === null) {
                $durationOrder = ($left->lastRenderMilliseconds === null) <=> ($right->lastRenderMilliseconds === null);
            } else {
                $durationOrder = $right->lastRenderMilliseconds <=> $left->lastRenderMilliseconds;
            }

            return $durationOrder ?: strcmp($left->url, $right->url);
        });

        $this->pages = $pages;
    }
}
