<?php

declare(strict_types=1);

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Facades\Frontend;
use Capell\HtmlCache\Livewire\SiteHealthCacheMap;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Livewire\Livewire;

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
    });
    Frontend::clearResolvedInstance(FrontendContextReader::class);
}
