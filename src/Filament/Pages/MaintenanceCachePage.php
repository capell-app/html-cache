<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Filament\Pages;

use BackedEnum;
use Capell\Admin\Support\SiteScope;
use Capell\Core\Models\Site;
use Capell\Frontend\Support\Maintenance\MaintenanceManifestStore;
use Capell\HtmlCache\Actions\BuildMaintenanceCacheOverviewAction;
use Capell\HtmlCache\Actions\DisableGlobalMaintenanceAction;
use Capell\HtmlCache\Actions\DisableSiteMaintenanceOverrideAction;
use Capell\HtmlCache\Actions\EnableGlobalMaintenanceAction;
use Capell\HtmlCache\Actions\EnableSiteMaintenanceOverrideAction;
use Capell\HtmlCache\Actions\PrepareMaintenanceCacheAction;
use Capell\HtmlCache\Actions\PrepareSiteMaintenanceCacheAction;
use Capell\HtmlCache\Data\MaintenanceCacheOverviewData;
use Capell\HtmlCache\Enums\MaintenanceGlobalAction;
use Capell\HtmlCache\Support\Maintenance\MaintenanceCachePermissions;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Override;
use RuntimeException;

class MaintenanceCachePage extends Page implements HasActions
{
    use InteractsWithActions;

    protected static ?string $slug = 'html-cache/maintenance-cache';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?int $navigationSort = 10;

    protected string $view = 'capell-html-cache::filament.pages.maintenance-cache';

    #[Override]
    public static function canAccess(): bool
    {
        return MaintenanceCachePermissions::canManage(auth()->user());
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return __('capell-html-cache::admin.maintenance_cache');
    }

    #[Override]
    public static function getNavigationGroup(): ?string
    {
        return __('capell-admin::navigation.group_monitoring');
    }

    #[Override]
    public function getTitle(): string|Htmlable
    {
        return __('capell-html-cache::admin.maintenance_cache');
    }

    /** @return Collection<int, Site> */
    public function sites(): Collection
    {
        return $this->accessibleSitesQuery()
            ->with(['siteDomains', 'language'])
            ->ordered()
            ->get();
    }

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return resolve(MaintenanceManifestStore::class)->read();
    }

    /**
     * The single typed projection the page and its actions read from. Always
     * recomputed from current manifest, site, and run state rather than
     * cached across the request, so the rendered state can never lag behind
     * what a write action just did.
     */
    public function overview(): MaintenanceCacheOverviewData
    {
        return BuildMaintenanceCacheOverviewAction::run($this->sites());
    }

    public function prepareSite(int $siteId): void
    {
        try {
            PrepareSiteMaintenanceCacheAction::run(auth()->user(), $siteId);
        } catch (RuntimeException $exception) {
            Notification::make()->warning()->title($exception->getMessage())->send();

            return;
        }

        Notification::make()
            ->success()
            ->title(__('capell-html-cache::admin.maintenance_cache_queued'))
            ->send();
    }

    public function enableSiteOverride(int $siteId): void
    {
        try {
            EnableSiteMaintenanceOverrideAction::run(auth()->user(), $siteId);
        } catch (RuntimeException $exception) {
            Notification::make()->warning()->title($exception->getMessage())->send();

            return;
        }

        Notification::make()
            ->success()
            ->title(__('capell-html-cache::admin.maintenance_site_updated'))
            ->send();
    }

    public function disableSiteOverride(int $siteId): void
    {
        DisableSiteMaintenanceOverrideAction::run(auth()->user(), $siteId);

        Notification::make()
            ->success()
            ->title(__('capell-html-cache::admin.maintenance_site_updated'))
            ->send();
    }

    /** @return array<int, Action> */
    #[Override]
    protected function getHeaderActions(): array
    {
        return match ($this->overview()->primaryAction()) {
            MaintenanceGlobalAction::Prepare => [$this->prepareAction()],
            MaintenanceGlobalAction::ReviewAndEnable => [$this->reviewAndEnableAction()],
            MaintenanceGlobalAction::ExitMaintenance => [$this->exitMaintenanceAction()],
            null => [],
        };
    }

    private function prepareAction(): Action
    {
        return Action::make('prepare')
            ->label(MaintenanceGlobalAction::Prepare->label())
            ->icon('heroicon-o-arrow-path')
            ->authorize(fn (): bool => MaintenanceCachePermissions::canManage(auth()->user()))
            ->action(function (): void {
                try {
                    PrepareMaintenanceCacheAction::run(auth()->user());
                } catch (RuntimeException $exception) {
                    Notification::make()->warning()->title($exception->getMessage())->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('capell-html-cache::admin.maintenance_cache_queued'))
                    ->send();
            });
    }

    private function reviewAndEnableAction(): Action
    {
        return Action::make('review-and-enable')
            ->label(MaintenanceGlobalAction::ReviewAndEnable->label())
            ->icon('heroicon-o-lock-closed')
            ->authorize(fn (): bool => MaintenanceCachePermissions::canManageGlobal(auth()->user()))
            ->requiresConfirmation()
            ->action(function (): void {
                try {
                    EnableGlobalMaintenanceAction::run(auth()->user());
                } catch (RuntimeException $exception) {
                    Notification::make()->warning()->title($exception->getMessage())->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('capell-html-cache::admin.global_maintenance_queued'))
                    ->send();
            });
    }

    private function exitMaintenanceAction(): Action
    {
        return Action::make('exit-maintenance')
            ->label(MaintenanceGlobalAction::ExitMaintenance->label())
            ->icon('heroicon-o-lock-open')
            ->color('success')
            ->authorize(fn (): bool => MaintenanceCachePermissions::canManageGlobal(auth()->user()))
            ->requiresConfirmation()
            ->action(function (): void {
                DisableGlobalMaintenanceAction::run(auth()->user());

                Notification::make()
                    ->success()
                    ->title(__('capell-html-cache::admin.global_maintenance_disabled'))
                    ->send();
            });
    }

    /**
     * @return Builder<Site>
     */
    private function accessibleSitesQuery(): Builder
    {
        return SiteScope::applyForCurrentActor(Site::query(), 'id', denyWhenMissingActor: true);
    }
}
