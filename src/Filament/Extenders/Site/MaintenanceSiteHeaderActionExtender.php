<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Filament\Extenders\Site;

use Capell\Admin\Contracts\Extenders\SiteHeaderActionExtender;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Admin\Support\SiteScope;
use Capell\Core\Enums\PageTypeEnum;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\Creator\PageCreator;
use Capell\Frontend\Actions\GenerateMaintenancePageCacheAction;
use Capell\HtmlCache\Enums\HtmlCachePermission;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Auth\Authenticatable;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

class MaintenanceSiteHeaderActionExtender implements SiteHeaderActionExtender
{
    /** @return array<int, Action> */
    public function actions(): array
    {
        return [
            Action::make('edit-404-page')
                ->label(__('capell-html-cache::admin.edit_404_page'))
                ->icon('heroicon-o-exclamation-circle')
                ->authorize(fn (): bool => $this->canManageMaintenance())
                ->action(fn (Site $record) => redirect()->to($this->pageUrl($record, PageTypeEnum::NotFound))),
            Action::make('edit-maintenance-page')
                ->label(__('capell-html-cache::admin.edit_maintenance_page'))
                ->icon('heroicon-o-wrench-screwdriver')
                ->authorize(fn (): bool => $this->canManageMaintenance())
                ->action(fn (Site $record) => redirect()->to($this->pageUrl($record, PageTypeEnum::Maintenance))),
            Action::make('generate-maintenance-page-cache')
                ->label(__('capell-html-cache::admin.generate_maintenance_page_cache'))
                ->icon('heroicon-o-arrow-path')
                ->authorize(fn (): bool => $this->canManageMaintenance())
                ->action(function (Site $record): void {
                    GenerateMaintenancePageCacheAction::run($record);

                    Notification::make()
                        ->success()
                        ->title(__('capell-html-cache::admin.maintenance_cache_generated'))
                        ->send();
                }),
        ];
    }

    private function pageUrl(Site $site, PageTypeEnum $type): string
    {
        $page = $site->getFirstPageByType($type->value);

        if (! $page instanceof Page) {
            $page = match ($type) {
                PageTypeEnum::Maintenance => resolve(PageCreator::class)->createMaintenancePage($site),
                PageTypeEnum::NotFound => resolve(PageCreator::class)->createErrorPage($site),
                default => null,
            };
        }

        return AdminSurfaceLookup::resource(ResourceEnum::Page)::getUrl('edit', ['record' => $page]);
    }

    private function canManageMaintenance(): bool
    {
        $actor = auth()->user();

        if (! $actor instanceof Authenticatable) {
            return false;
        }

        if (SiteScope::isGlobalActor($actor)) {
            return true;
        }

        try {
            return $actor->hasPermissionTo(HtmlCachePermission::ManageMaintenance->value) === true;
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }
}
