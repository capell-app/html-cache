<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Telemetry;

use Capell\Core\Models\PageUrl;
use Capell\Core\Models\SiteDomain;
use Capell\HtmlCache\Data\HtmlCacheRequestMetricsData;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

final readonly class HtmlCacheRequestObservationStore
{
    public const string TABLE = 'html_cache_request_observations';

    public static function pageUrlHash(PageUrl $pageUrl): string
    {
        // Wildcard hosts share their configured CMS route. Request aliases must
        // not create unbounded rows or store visitor-provided URL values.
        $domain = $pageUrl->getRelation('siteDomain');
        if (! $domain instanceof SiteDomain) {
            throw new LogicException('Observation identity requires the resolved site domain.');
        }

        return hash('sha256', json_encode([
            $pageUrl->getKey(), $domain->getKey(), $pageUrl->url,
            $domain->getRawOriginal('scheme'), $domain->getRawOriginal('domain'), $domain->getRawOriginal('path'),
        ], JSON_THROW_ON_ERROR));
    }

    public function record(string $urlHash, int $siteId, int $languageId, ?float $renderMilliseconds, int $sampleWeight): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $observedAt = now();
        // These rows are telemetry, never rendering dependencies. Updating them
        // must not trigger model observers that invalidate the public HTML.
        DB::table(self::TABLE)->insertOrIgnore([
            'url_hash' => $urlHash,
            'site_id' => $siteId,
            'language_id' => $languageId,
            'observation_started_at' => $observedAt,
            'last_observed_at' => $observedAt,
        ]);
        $attributes = ['last_observed_at' => $observedAt];
        if ($renderMilliseconds !== null) {
            $attributes['last_render_milliseconds'] = max(0, $renderMilliseconds);
            $attributes['last_rendered_at'] = $observedAt;
        }

        $this->query($urlHash, $siteId, $languageId)->incrementEach([
            'weighted_hits' => $renderMilliseconds === null ? max(1, $sampleWeight) : 0,
            'weighted_misses' => $renderMilliseconds === null ? 0 : max(1, $sampleWeight),
        ], $attributes);
    }

    public function read(string $urlHash, int $siteId, int $languageId): ?HtmlCacheRequestMetricsData
    {
        if (! Schema::hasTable(self::TABLE)) {
            return null;
        }

        $row = $this->query($urlHash, $siteId, $languageId)->first();
        if ($row === null) {
            return null;
        }

        $hits = (int) $row->weighted_hits;
        $misses = (int) $row->weighted_misses;

        return new HtmlCacheRequestMetricsData(
            lastRenderMilliseconds: is_numeric($row->last_render_milliseconds) ? (float) $row->last_render_milliseconds : null,
            hitRate: $hits + $misses > 0 ? 100 * $hits / ($hits + $misses) : null,
            weightedHits: $hits,
            weightedMisses: $misses,
            observedSince: CarbonImmutable::parse($row->observation_started_at)->toIso8601String(),
        );
    }

    private function query(string $urlHash, int $siteId, int $languageId): Builder
    {
        return DB::table(self::TABLE)->where('url_hash', $urlHash)
            ->where('site_id', $siteId)->where('language_id', $languageId);
    }
}
