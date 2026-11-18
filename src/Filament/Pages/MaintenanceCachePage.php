<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Filament\Pages;

use BackedEnum;
use Capell\Admin\Contracts\Diagnostics\SiteHealthSubpage;
use Capell\Admin\Enums\NavigationGroupEnum;
use Capell\Admin\Filament\Pages\SiteHealthPage;
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

class MaintenanceCachePage extends Page implements HasActions, SiteHealthSubpage
{
    use InteractsWithActions;

    protected static bool $shouldRegisterNavigation = true;

    protected static ?string $slug = 'html-cache/maintenance-cache';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?int $navigationSort = 10;

    protected string $view = 'capell-html-cache::filament.pages.maintenance-cache';

    private ?MaintenanceCacheOverviewData $requestOverview = null;

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
    public static function getNavigationParentItem(): ?string
    {
        return SiteHealthPage::canAccess() ? SiteHealthPage::getNavigationLabel() : null;
    }

    #[Override]
    public static function getNavigationGroup(): NavigationGroupEnum
    {
        // Site Health children must share its group to nest beneath it.
        return SiteHealthPage::getNavigationGroup();
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
     * Share a request-local snapshot across header actions, status and polling.
     * A worker completing during render must not remove polling while leaving
     * the terminal action absent. Refresh and writes invalidate this snapshot.
     */
    public function overview(): MaintenanceCacheOverviewData
    {
        return $this->requestOverview ??= BuildMaintenanceCacheOverviewAction::run($this->sites());
    }

    public function refreshStatus(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->refreshOverview();
    }

    public function prepareSite(int $siteId): void
    {
        try {
            PrepareSiteMaintenanceCacheAction::run(auth()->user(), $siteId);
        } catch (RuntimeException $runtimeException) {
            Notification::make()->warning()->title($runtimeException->getMessage())->send();

            return;
        } finally {
            $this->refreshOverview();
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
        } catch (RuntimeException $runtimeException) {
            Notification::make()->warning()->title($runtimeException->getMessage())->send();

            return;
        } finally {
            $this->refreshOverview();
        }

        Notification::make()
            ->success()
            ->title(__('capell-html-cache::admin.maintenance_site_updated'))
            ->send();
    }

    public function disableSiteOverride(int $siteId): void
    {
        try {
            DisableSiteMaintenanceOverrideAction::run(auth()->user(), $siteId);
        } finally {
            $this->refreshOverview();
        }

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

    private function refreshOverview(): void
    {
        $this->requestOverview = null;
        $this->cachedHeaderActions = [];
        $this->cacheInteractsWithHeaderActions();
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
                } catch (RuntimeException $runtimeException) {
                    Notification::make()->warning()->title($runtimeException->getMessage())->send();

                    return;
                } finally {
                    $this->refreshOverview();
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
                } catch (RuntimeException $runtimeException) {
                    Notification::make()->warning()->title($runtimeException->getMessage())->send();

                    return;
                } finally {
                    $this->refreshOverview();
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
                try {
                    DisableGlobalMaintenanceAction::run(auth()->user());
                } finally {
                    $this->refreshOverview();
                }

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
