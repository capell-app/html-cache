<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions\Dashboard;

use Capell\Admin\Support\SiteScope;
use Capell\HtmlCache\Models\StaleCachedUrl;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static Collection<int, array{id: string, url: string, status: string, attempts: int, reason: string, updated: string}> run(int $limit = 5)
 */
final class BuildHtmlCacheStaleQueueRowsAction
{
    use AsFake;
    use AsObject;

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
            ->latest('updated_at')
            ->limit($limit)
            ->get()
            ->map($this->staleUrlRow(...))
            ->values();
    }

    /**
     * @return array{id: string, url: string, status: string, attempts: int, reason: string, updated: string}
     */
    private function staleUrlRow(StaleCachedUrl $staleUrl): array
    {
        return [
            'id' => 'stale-url-' . $staleUrl->id,
            'url' => $staleUrl->url,
            'status' => (string) __('capell-html-cache::dashboard.status_' . $staleUrl->status),
            'attempts' => $staleUrl->attempts,
            'reason' => $staleUrl->reason ?? (string) __('capell-html-cache::dashboard.not_available'),
            'updated' => $staleUrl->updated_at?->diffForHumans() ?? (string) __('capell-html-cache::dashboard.not_available'),
        ];
    }
}
