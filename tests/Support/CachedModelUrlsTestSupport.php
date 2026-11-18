<?php

declare(strict_types=1);

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Data\FrontendRenderPayload;
use Capell\Frontend\Facades\Frontend;
use Capell\HtmlCache\Actions\BuildHtmlCacheOriginDecisionAction;
use Capell\HtmlCache\Actions\RetireCachedUrlAction;
use Capell\HtmlCache\Enums\HtmlCacheEligibilityReason;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Livewire\SiteHealthCacheMap;
use Capell\HtmlCache\Models\StaleCachedUrl;
use Capell\HtmlCache\Support\Cache\HtmlCacheStore;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;

/**
 * The cached page entries on the page cache disk. The Frontend static error and maintenance
 * trees are not page entries: a full clear deliberately keeps them, so they are left out here
 * and every other file still counts.
 *
 * @return list<string>
 */
function htmlCachePageEntries(?Filesystem $disk = null): array
{
    return array_values(array_filter(
        ($disk ?? Storage::disk('page_cache'))->allFiles(),
        static fn (string $file): bool => ! HtmlCacheStore::isStaticArtefactDirectory(explode('/', $file, 2)[0]),
    ));
}

function htmlCacheMapTestComponent(int $siteId, string $modelType): mixed
{
    return Livewire::test(SiteHealthCacheMap::class, ['siteId' => $siteId])
        ->set('selectedModelType', $modelType);
}

function bindHtmlCacheFrontendContext(?Pageable $page = null): void
{
    config()->set('capell-html-cache.enabled', true);

    if ($page instanceof EloquentModel) {
        $page->loadMissing('blueprint');
    }

    app()->instance(FrontendContextReader::class, new readonly class($page) implements FrontendContextReader
    {
        public function __construct(private ?Pageable $page) {}

        public function site(): ?Site
        {
            return null;
        }

        public function language(): ?Language
        {
            return null;
        }

        public function page(): ?Pageable
        {
            return $this->page;
        }

        public function layout(): ?Layout
        {
            return null;
        }

        public function theme(): ?Theme
        {
            return null;
        }

        /**
         * @return array<string, mixed>
         */
        public function params(): array
        {
            return [];
        }

        public function slug(): ?string
        {
            return null;
        }

        public function isError(): bool
        {
            return false;
        }

        public function setFrontendData(string $key, mixed $value): self
        {
            return $this;
        }

        public function getFrontendData(?string $key = null): mixed
        {
            return $key === null ? [] : null;
        }

        public function renderPayload(): FrontendRenderPayload
        {
            return FrontendRenderPayload::fromBag([]);
        }
    });
    Frontend::clearResolvedInstance(FrontendContextReader::class);
}

/** Declare the origin boundary explicitly when testing the retirement consumer directly. */
function retireDeclaredHtmlCacheOriginResponse(Request $request, Response $response, ?StaleCachedUrl $stale = null, bool $suppressPurge = false): ?HtmlCacheEligibilityReason
{
    $request->attributes->set(
        HtmlCacheMiddleware::ORIGIN_DECISION_ATTRIBUTE,
        BuildHtmlCacheOriginDecisionAction::run($request, $response),
    );

    return RetireCachedUrlAction::run($request, $response, $stale, $suppressPurge);
}
