<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Admin\Data\Diagnostics\DiagnosticCheckData;
use Capell\Admin\Support\SiteScope;
use Capell\HtmlCache\Models\CachedModelUrl;
use Illuminate\Database\Eloquent\Builder;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static list<DiagnosticCheckData> run(?int $siteId = null)
 */
final class BuildCachedModelUrlDiagnosticsAction
{
    use AsObject;

    /**
     * @return list<DiagnosticCheckData>
     */
    public function handle(?int $siteId = null): array
    {
        $configuredLimit = config('capell-html-cache.site_health_cached_url_limit');
        $limit = is_numeric($configuredLimit) ? max(1, (int) $configuredLimit) : 20;
        $query = $this->scopedCachedUrlsQuery($siteId);
        $total = (clone $query)->count();

        if ($total === 0) {
            return [
                new DiagnosticCheckData(
                    status: 'amber',
                    label: (string) __('capell-html-cache::admin.tracked_cached_urls'),
                    detail: (string) __('capell-html-cache::admin.no_cached_model_urls_tracked'),
                ),
            ];
        }

        return [
            new DiagnosticCheckData(
                status: $total > $limit ? 'amber' : 'green',
                label: (string) __('capell-html-cache::admin.tracked_cached_urls'),
                detail: (string) __('capell-html-cache::admin.cached_model_urls_summary', [
                    'shown' => min($total, $limit),
                    'total' => $total,
                ]),
            ),
        ];
    }

    /**
     * @return Builder<CachedModelUrl>
     */
    private function scopedCachedUrlsQuery(?int $siteId): Builder
    {
        /** @var Builder<CachedModelUrl> $query */
        $query = CachedModelUrl::query();

        $query = SiteScope::applyForCurrentActor($query, denyWhenMissingActor: true);

        if ($siteId !== null) {
            $query->where('site_id', $siteId);
        }

        return $query;
    }
}
