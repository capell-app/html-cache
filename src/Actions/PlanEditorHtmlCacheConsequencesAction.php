<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Admin\Contracts\EditorImpact\EditorImpactConsequencePlanner;
use Capell\Core\Actions\LoadSiteDomainFromUrlAction;
use Capell\Core\Data\EditorImpact\EditorImpactConsequenceData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Models\HtmlCacheGenerationRun;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Override;

final class PlanEditorHtmlCacheConsequencesAction implements EditorImpactConsequencePlanner
{
    #[Override]
    public function plan(Model $record): array
    {
        if (! ($record instanceof Page || $record instanceof Media || $record instanceof Layout)
            || ! $record->exists || ! Gate::allows('update', $record)) {
            return [];
        }

        if (! CapellCore::isPackageInstalled('capell-app/html-cache')
            || config('capell-html-cache.enabled', true) !== true
            || ! Schema::hasTable('cached_model_urls')) {
            return [];
        }

        $scheduled = config('capell-html-cache.invalidation.mode', 'instant') === 'scheduled';
        $urls = ResolveCachedUrlsForModelAction::run($record, includePageUrls: ! $scheduled);

        if ($record instanceof Layout) {
            $siteIds = $record->site_id === null ? Site::query()->pluck('id')->filter(static fn (mixed $id): bool => is_int($id) || (is_string($id) && is_numeric($id)))->map(static fn (int|string $id): int => (int) $id)->all() : [$record->site_id];
            $keys = array_map(static fn (int $id): string => 'site-' . $id, $siteIds);
            $urls = $urls->merge(ResolveCachedUrlsForSurrogateKeysAction::run($keys));
        }

        // A shared dependency can be cached on sites this editor cannot inspect.
        $allSites = Site::query()->excludingPreview()->with('siteDomains')->get();
        $sites = $allSites->filter(static fn (Site $site): bool => Gate::allows('view', $site));
        $siteIds = $sites->modelKeys();
        $urls = $urls->unique()->filter(static function (string $url) use ($siteIds, $allSites): bool {
            $resolved = LoadSiteDomainFromUrlAction::run($url, $allSites);
            $domain = is_array($resolved) ? $resolved[0] : null;

            return $domain instanceof SiteDomain && in_array($domain->site_id, $siteIds, true);
        })->sort()->values();
        $count = $urls->count();
        $estimate = null;
        $basis = __('capell-html-cache::editor-impact.estimate_unavailable');
        $affectedSiteIds = CachedModelUrl::query()->whereIn('url', $urls)->whereIn('site_id', $siteIds)->distinct()->pluck('site_id')->filter(static fn (mixed $id): bool => is_int($id) || (is_string($id) && is_numeric($id)))->map(static fn (int|string $id): int => (int) $id)->all();
        $run = Schema::hasColumn('html_cache_generation_runs', 'site_ids') ? HtmlCacheGenerationRun::query()->where('status', HtmlCacheGenerationRun::STATUS_COMPLETED)
            ->where('failed_sites', 0)->whereNotNull('started_at')->whereNotNull('finished_at')
            ->latest('finished_at')->limit(20)->get()
            ->first(static fn (HtmlCacheGenerationRun $run): bool => $affectedSiteIds !== []
                && array_diff($affectedSiteIds, $run->site_ids ?? []) === []
                && array_diff($run->site_ids ?? [], $siteIds) === []) : null;

        if ($run instanceof HtmlCacheGenerationRun && $run->started_at !== null && $run->finished_at !== null) {
            $duration = $run->started_at->diffInSeconds($run->finished_at);
            $referenceCount = CachedModelUrl::query()->whereIn('site_id', $run->site_ids ?? [])->distinct()->count('url');

            if ($duration > 0 && $referenceCount > 0) {
                $estimate = round($count * $duration / $referenceCount, 2);
                $basis = __('capell-html-cache::editor-impact.estimate_basis', ['affected' => $count, 'seconds' => $duration, 'total' => $referenceCount]);
            }
        }

        $consequences = [
            new EditorImpactConsequenceData(
                label: __('capell-html-cache::editor-impact.html_label'),
                description: __('capell-html-cache::editor-impact.' . ($record instanceof Layout ? 'html_layout' : ($scheduled ? 'html_scheduled' : 'html_instant'))),
                count: $count,
                urls: array_values($urls->take(10)->all()),
                estimatedSeconds: $estimate,
                estimateBasis: $basis,
            ),
        ];

        if ($record instanceof Page) {
            $translationUrls = collect();
            foreach ($record->translations as $translation) {
                $translationUrls = $translationUrls->merge(ResolveCachedUrlsForModelAction::run($translation, includePageUrls: ! $scheduled));
            }

            $visibleTranslationUrls = CachedModelUrl::query()->whereIn('url', $translationUrls)
                ->whereIn('site_id', $siteIds)->whereNotIn('url', $urls)->distinct()->orderBy('url')->pluck('url')->filter(static fn (mixed $url): bool => is_string($url));
            $consequences[] = new EditorImpactConsequenceData(
                label: __('capell-html-cache::editor-impact.translation_label'),
                description: __('capell-html-cache::editor-impact.translation_description'),
                count: $visibleTranslationUrls->count(),
                urls: array_values($visibleTranslationUrls->take(10)->all()),
            );
        }

        return $consequences;
    }
}
