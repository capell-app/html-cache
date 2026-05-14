<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions\Dashboard;

use Capell\Admin\Support\SiteScope;
use Capell\HtmlCache\Models\StaleCachedUrl;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * @method static Collection<int, array{id: string, url: string, status: string, attempts: int, reason: string, updated: string}> run(int $limit = 5)
 */
final class BuildHtmlCacheStaleQueueRowsAction
{
    use AsAction;

    /**
     * @return Collection<int, array{id: string, url: string, status: string, attempts: int, reason: string, updated: string}>
     */
    public function handle(int $limit = 5): Collection
    {
        return SiteScope::applyForCurrentActor(StaleCachedUrl::query(), denyWhenMissingActor: true)
            ->whereIn('status', [
                StaleCachedUrl::STATUS_FAILED,
                StaleCachedUrl::STATUS_EXHAUSTED,
                StaleCachedUrl::STATUS_PENDING,
                StaleCachedUrl::STATUS_PROCESSING,
            ])
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (StaleCachedUrl $staleUrl): array => [
                'id' => 'stale-url-' . $staleUrl->id,
                'url' => $staleUrl->url,
                'status' => __('capell-html-cache::dashboard.status_' . $staleUrl->status),
                'attempts' => $staleUrl->attempts,
                'reason' => $staleUrl->reason ?? __('capell-html-cache::dashboard.not_available'),
                'updated' => $staleUrl->updated_at?->diffForHumans() ?? __('capell-html-cache::dashboard.not_available'),
            ])
            ->values();
    }
}
