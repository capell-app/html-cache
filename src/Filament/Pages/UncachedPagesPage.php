<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Filament\Pages;

use BackedEnum;
use Capell\Admin\Contracts\Diagnostics\SiteHealthSubpage;
use Capell\Admin\Filament\Pages\SiteHealthPage;
use Capell\HtmlCache\Actions\BuildUncachedPagesAction;
use Capell\HtmlCache\Filament\Resources\CachedModelUrls\CachedModelUrlResource;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Override;

final class UncachedPagesPage extends Page implements SiteHealthSubpage
{
    protected static ?string $slug = 'html-cache/uncached-pages';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentMagnifyingGlass;

    protected static ?int $navigationSort = 12;

    protected string $view = 'capell-html-cache::filament.pages.uncached-pages';

    #[Override]
    public static function canAccess(): bool
    {
        return CachedModelUrlResource::canAccess();
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return __('capell-html-cache::admin.uncached_pages');
    }

    #[Override]
    public static function getNavigationParentItem(): ?string
    {
        return SiteHealthPage::canAccess() ? SiteHealthPage::getNavigationLabel() : null;
    }

    #[Override]
    public static function getNavigationGroup(): string
    {
        return __('capell-admin::navigation.group_monitoring');
    }

    #[Override]
    public function getTitle(): string
    {
        return self::getNavigationLabel();
    }

    /** @return array<string, mixed> */
    #[Override]
    protected function getViewData(): array
    {
        return ['overview' => BuildUncachedPagesAction::run()];
    }
}
