<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Cache;

use Capell\HtmlCache\Models\HtmlCacheDependencyTrackingEpoch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

/**
 * Whether every cached page was written by a render whose dependency index can
 * be trusted. Pages cached by an older tracking version may show a model without
 * recording it (for example a listing served from cached render data), so a
 * withdrawal clears everything until a full clear has removed those pages.
 */
final class HtmlCacheDependencyTracking
{
    /**
     * Raise when renders start recording dependencies that earlier cached pages
     * did not: the next withdrawal then clears everything once and trusts the
     * index again. Version 1: cached render data declares the models it shows.
     */
    public const int CURRENT_VERSION = 1;

    public static function installed(): bool
    {
        return Schema::hasTable((new HtmlCacheDependencyTrackingEpoch)->getTable());
    }

    public static function isCurrent(): bool
    {
        return self::installed()
            && HtmlCacheDependencyTrackingEpoch::query()->where('version', '>=', self::CURRENT_VERSION)->exists();
    }

    /** Call only once no cached page written before now remains. */
    public static function markCurrent(): void
    {
        if (! self::installed()) {
            return;
        }

        HtmlCacheDependencyTrackingEpoch::query()->updateOrCreate(
            ['version' => self::CURRENT_VERSION],
            ['trusted_since' => CarbonImmutable::now()],
        );
    }
}
