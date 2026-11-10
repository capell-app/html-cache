<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Core\Exceptions\UrlMissingSiteDomainException;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Translation;
use Capell\HtmlCache\Data\HtmlCacheWithdrawalData;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Support\Cache\HtmlCacheDependencyTracking;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Capell\HtmlCache\Support\Cache\HtmlCachePublicationGuard;
use Capell\HtmlCache\Support\Cache\HtmlCacheStore;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsJob;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static HtmlCacheWithdrawalData run(string $morphClass, int $modelKey)
 */
final class WithdrawCachedUrlsForModelAction
{
    use AsFake;
    use AsJob;
    use AsObject;

    /**
     * Withdraw the public HTML that renders one model: its own page URLs in
     * every site and language, and every cached URL whose dependency index
     * records it (listings, menus, related links and other embeds). Other
     * cached pages keep serving.
     *
     * When the dependency index cannot be trusted for this model the whole
     * cache is cleared instead, because under-withdrawing would keep serving
     * content that is no longer public.
     */
    public function handle(string $morphClass, int $modelKey): HtmlCacheWithdrawalData
    {
        $isPage = $morphClass === (new Page)->getMorphClass();
        $fullClearReason = $this->untrackedReason($morphClass);
        $dependants = [];
        $ownUrls = [];

        if ($fullClearReason === null) {
            $modelKeys = $this->modelKeys($morphClass, $modelKey, $isPage);

            // Record the withdrawal before reading the index. A render whose
            // index is recorded after this point sees the withdrawal itself;
            // one recorded before it is found below. See RecordCachedModelUrlsAction.
            resolve(HtmlCachePublicationGuard::class)->fence([], array_keys($modelKeys));

            $dependants = $this->recordedDependants($modelKeys);
            $ownUrls = $isPage ? $this->pageUrls($modelKey) : [];

            if ($isPage && $ownUrls === []) {
                $fullClearReason = 'the page has no served URL left to withdraw';
            } elseif ($dependants === []) {
                if ($this->anyCached($ownUrls)) {
                    // Its own render records it, so a cached copy without a
                    // record means dependency tracking did not run for it.
                    $fullClearReason = $isPage
                        ? 'the page is cached but no cached URL records it'
                        : 'no cached URL records this model yet';
                } elseif ($isPage) {
                    // Never cached and recorded by nothing cached: no cached
                    // page can show it. The fence above stops renders in flight.
                    return HtmlCacheWithdrawalData::targeted(0);
                } else {
                    $fullClearReason = 'no cached URL records this model yet';
                }
            }
        }

        if ($fullClearReason !== null) {
            Log::info('HTML cache withdrawal cleared the whole cache because the pages that render this model are not known.', [
                'model_type' => $morphClass,
                'model_id' => $modelKey,
                'reason' => $fullClearReason,
            ]);
            ClearAllHtmlCacheAction::run();

            return HtmlCacheWithdrawalData::fullClear($fullClearReason);
        }

        $urls = $dependants;
        $recordedHashes = [];
        foreach ($dependants as $dependant) {
            $recordedHashes[$dependant->url_hash] = true;
        }

        foreach ($ownUrls as [$url]) {
            if (! isset($recordedHashes[CachedModelUrl::hashUrl($url)])) {
                $urls[] = $url;
            }
        }

        return HtmlCacheWithdrawalData::targeted(WithdrawCachedUrlsAction::run($urls));
    }

    /**
     * A page is rendered through its translations too, so embeds that only
     * read a translation depend on the page as well.
     *
     * @return array<string, array{0: string, 1: int}> keyed by HtmlCachePublicationGuard::modelKey()
     */
    private function modelKeys(string $morphClass, int $modelKey, bool $isPage): array
    {
        $models = [HtmlCachePublicationGuard::modelKey($morphClass, $modelKey) => [$morphClass, $modelKey]];

        if (! $isPage) {
            return $models;
        }

        $translationMorphClass = (new Translation)->getMorphClass();
        $translationIds = Translation::query()
            ->withTrashed()
            ->where('translatable_type', $morphClass)
            ->where('translatable_id', $modelKey)
            ->pluck('id');

        foreach ($translationIds as $translationId) {
            if (is_numeric($translationId)) {
                $models[HtmlCachePublicationGuard::modelKey($translationMorphClass, (int) $translationId)] = [$translationMorphClass, (int) $translationId];
            }
        }

        return $models;
    }

    private function untrackedReason(string $morphClass): ?string
    {
        if (! Schema::hasTable((new CachedModelUrl)->getTable())) {
            return 'the cached URL dependency index is not installed';
        }

        if (! resolve(HtmlCachePublicationGuard::class)->withdrawalsInstalled()) {
            return 'the model withdrawal table is not installed';
        }

        if (! HtmlCacheDependencyTracking::isCurrent()) {
            return 'cached pages may predate the current dependency tracking';
        }

        // A queue backlog can leave public pages cached with no record yet, and the
        // fence only withdraws them once their queued registration finally runs.
        if (config('capell-html-cache.model_event_registration_mode') === 'async') {
            return 'queued dependency registration may not have recorded every cached page yet';
        }

        $modelClass = Relation::getMorphedModel($morphClass) ?? $morphClass;

        if (in_array($modelClass, [Page::class, Translation::class], true)) {
            return null;
        }

        foreach (CapellCore::getModels() as $trackedClass) {
            if ($trackedClass === $modelClass && is_subclass_of($trackedClass, Model::class)) {
                return null;
            }
        }

        return 'renders do not record this model type';
    }

    /**
     * One row per cached URL and owner, so each URL is withdrawn once.
     *
     * @param  array<string, array{0: string, 1: int}>  $modelKeys
     * @return list<CachedModelUrl>
     */
    private function recordedDependants(array $modelKeys): array
    {
        $idsByType = [];
        foreach ($modelKeys as [$type, $id]) {
            $idsByType[$type][] = $id;
        }

        $rows = CachedModelUrl::query()
            ->where(function (Builder $query) use ($idsByType): void {
                foreach ($idsByType as $type => $ids) {
                    $query->orWhere(static function (Builder $query) use ($type, $ids): void {
                        $query->where('cacheable_type', $type)->whereIn('cacheable_id', $ids);
                    });
                }
            })
            ->orderBy('id')
            ->get();

        $unique = [];
        foreach ($rows as $row) {
            $unique[$row->url_hash . '|' . $row->site_id . '|' . $row->site_domain_id] ??= $row;
        }

        return array_values($unique);
    }

    /**
     * Every served address of the page, including the addresses its deletion
     * trashed with it: their cached copies are still on disk. Addresses
     * trashed earlier stopped serving this page then, and may since belong to
     * another page.
     *
     * @return list<array{0: string, 1: SiteDomain}>
     */
    private function pageUrls(int $pageId): array
    {
        $urls = [];
        $page = Page::query()->withTrashed()->find($pageId);
        $pageDeletedAt = $page instanceof Page ? $page->getAttribute($page->getDeletedAtColumn()) : null;

        $pageUrls = PageUrl::query()
            ->withTrashed()
            ->where('pageable_type', (new Page)->getMorphClass())
            ->where('pageable_id', $pageId)
            ->where(static function (Builder $query) use ($pageDeletedAt): void {
                $query->whereNull('deleted_at');

                if ($pageDeletedAt !== null) {
                    $query->orWhere('deleted_at', '>=', $pageDeletedAt);
                }
            })
            ->with(['siteDomain' => static fn (BuilderContract $query): BuilderContract => $query->withTrashed()])
            ->get();

        foreach ($pageUrls as $pageUrl) {
            $siteDomain = $pageUrl->getRelation('siteDomain');
            if (! $siteDomain instanceof SiteDomain) {
                continue;
            }

            try {
                $urls[$pageUrl->fullUrl()] = [$pageUrl->fullUrl(), $siteDomain];
            } catch (UrlMissingSiteDomainException) {
                continue;
            }
        }

        return array_values($urls);
    }

    /**
     * Whether any of the page's addresses holds a cached page. A cached 404
     * does not count: it cannot show the page's content.
     *
     * @param  list<array{0: string, 1: SiteDomain}>  $ownUrls
     */
    private function anyCached(array $ownUrls): bool
    {
        $paths = resolve(HtmlCachePathResolver::class);
        $store = resolve(HtmlCacheStore::class);

        foreach ($ownUrls as [$url, $siteDomain]) {
            if ($paths->hasSafeKey($url) && $store->exists($paths->pathForRequestUrl($url, $siteDomain))) {
                return true;
            }
        }

        return false;
    }
}
