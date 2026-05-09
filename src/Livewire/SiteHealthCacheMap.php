<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Livewire;

use Capell\Admin\Support\SiteScope;
use Capell\HtmlCache\Filament\Resources\CachedModelUrls\Tables\CachedModelUrlsTable;
use Capell\HtmlCache\Models\CachedModelUrl;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Contracts\TranslatableContentDriver;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

final class SiteHealthCacheMap extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public ?int $siteId = null;

    /** @var list<string> */
    public array $clearedCacheMapRecordKeys = [];

    public function mount(?int $siteId = null): void
    {
        $this->siteId = $siteId;
    }

    public function table(Table $table): Table
    {
        return CachedModelUrlsTable::configure($table, $this->query(), isSiteScoped: $this->siteId !== null);
    }

    public function render(): View
    {
        return view('capell-html-cache::livewire.site-health-cache-map');
    }

    public function makeFilamentTranslatableContentDriver(): ?TranslatableContentDriver
    {
        return null;
    }

    /**
     * @return Model|array<string, mixed>|null
     */
    public function getTableRecord(?string $key): Model|array|null
    {
        if ($key === null) {
            return null;
        }

        /** @var Builder<CachedModelUrl> $query */
        $query = CachedModelUrl::query();

        if ($this->siteId !== null) {
            $query->where('site_id', $this->siteId);
        }

        $record = $query->find($key);

        if ($record instanceof CachedModelUrl) {
            return $record;
        }

        return (new CachedModelUrl)->forceFill([
            'id' => (int) $key,
            'url' => '',
            'url_hash' => '',
            'path' => '/',
            'cacheable_type' => CachedModelUrl::class,
            'cacheable_id' => 0,
        ]);
    }

    public function rememberClearedCacheMapRecordKey(string $key): void
    {
        $this->clearedCacheMapRecordKeys[] = $key;
    }

    /**
     * @return Builder<CachedModelUrl>
     */
    private function query(): Builder
    {
        /** @var Builder<CachedModelUrl> $query */
        $query = SiteScope::applyForCurrentActor(CachedModelUrl::query(), denyWhenMissingActor: true);

        if ($this->siteId !== null) {
            $query->where('site_id', $this->siteId);
        }

        return $query;
    }
}
