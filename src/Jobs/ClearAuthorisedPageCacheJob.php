<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Jobs;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\SiteDomain;
use Capell\HtmlCache\Actions\ClearCachedUrlAction;
use Capell\HtmlCache\Actions\DeletePageCacheAction;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Support\HtmlCacheAuthorization;
use Capell\HtmlCache\Support\HtmlCacheAuthorizationMemo;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Clears a page's stored HTML on behalf of the admin who asked for it.
 *
 * The actor and the page are re-read from the database when the job runs and
 * the actor's permission and site access are checked again, so a job that was
 * queued before access was withdrawn, or replayed later, clears nothing.
 *
 * A page is cleared through every row stored for it, so addresses it had
 * before a slug change and copies captured on other ports go too. Each row is
 * pinned to its own site and cleared only when the actor may clear that row.
 */
final class ClearAuthorisedPageCacheJob implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public readonly Model&Authenticatable $actor,
        public readonly Pageable|PageUrl $record,
        public readonly ?bool $refresh = null,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(): void
    {
        resolve(HtmlCacheAuthorizationMemo::class)->forget();

        if (! HtmlCacheAuthorization::canClearPage($this->actor, $this->record)) {
            Log::warning('html-cache: refused a queued page cache clear the requesting user is no longer allowed to run.', [
                'actor_id' => $this->actor->getKey(),
                'record_type' => $this->record->getMorphClass(),
                'record_id' => $this->record->getKey(),
            ]);

            return;
        }

        if ($this->record instanceof Pageable && $this->record instanceof Model) {
            $this->clearStoredRows($this->record);

            return;
        }

        DeletePageCacheAction::run($this->record, $this->refresh);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('html-cache: a queued page cache clear failed.', [
            'record_type' => $this->record->getMorphClass(),
            'record_id' => $this->record->getKey(),
            'error' => $exception?->getMessage(),
        ]);
    }

    /**
     * Clears exactly the rows checked here, so a row recorded meanwhile is never
     * cleared unchecked, then the page's current addresses, which canClearPage()
     * has already authorised. A row the actor may not clear (another site's, or
     * one whose domain has gone) is kept and logged rather than blocking the rest.
     */
    private function clearStoredRows(Pageable&Model $page): void
    {
        $refresh = $this->refresh ?? (bool) config('capell-admin.auto_refresh_cache');
        $rows = CachedModelUrl::query()
            ->with(['site', 'siteDomain'])
            ->where('cacheable_type', $page->getMorphClass())
            ->where('cacheable_id', $page->getKey())
            ->get();

        foreach ($rows as $row) {
            if (! HtmlCacheAuthorization::canClearCachedUrl($this->actor, $row)) {
                Log::warning('html-cache: kept a stored address of a page the requesting user may not clear.', [
                    'actor_id' => $this->actor->getKey(),
                    'record_type' => $page->getMorphClass(),
                    'record_id' => $page->getKey(),
                    'cached_model_url_id' => $row->getKey(),
                ]);

                continue;
            }

            ClearCachedUrlAction::run($row, refresh: $refresh, allPorts: true);
        }

        $pageUrls = PageUrl::query()
            ->where('pageable_type', $page->getMorphClass())
            ->where('pageable_id', $page->getKey())
            ->with('siteDomain')
            ->get()
            // A URL in a language with no site domain is not served, so it has no cached page to clear.
            ->filter(static fn (PageUrl $pageUrl): bool => $pageUrl->getRelation('siteDomain') instanceof SiteDomain);

        foreach ($pageUrls as $pageUrl) {
            ClearCachedUrlAction::run($pageUrl->fullUrl(), refresh: $refresh, allPorts: true);
        }
    }
}
