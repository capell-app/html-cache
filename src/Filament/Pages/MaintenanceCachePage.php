<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Filament\Pages;

use BackedEnum;
use Capell\Admin\Support\SiteScope;
use Capell\Core\Models\Site;
use Capell\Frontend\Actions\GenerateAllMaintenancePageCachesAction;
use Capell\Frontend\Actions\GenerateMaintenancePageCacheAction;
use Capell\Frontend\Support\Maintenance\MaintenanceManifestStore;
use Capell\HtmlCache\Enums\HtmlCachePermission;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Override;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

class MaintenanceCachePage extends Page implements HasActions
{
    use InteractsWithActions;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?string $slug = 'maintenance-cache';

    protected static ?int $navigationSort = 10;

    protected string $view = 'capell-html-cache::filament.pages.maintenance-cache';

    #[Override]
    public static function canAccess(): bool
    {
        return self::canManageMaintenance();
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
        return Site::query()
            ->with(['siteDomains', 'language'])
            ->ordered()
            ->get();
    }

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return resolve(MaintenanceManifestStore::class)->read();
    }

    public function toggleSite(int $siteId): void
    {
        $manifest = $this->manifest();
        $current = data_get($manifest, 'sites.' . $siteId . '.active') === true;

        if (! $current && data_get($manifest, 'sites.' . $siteId . '.domains', []) === []) {
            $site = Site::query()->findOrFail($siteId);
            GenerateMaintenancePageCacheAction::run($site);
        }

        resolve(MaintenanceManifestStore::class)->setSiteActive($siteId, ! $current);

        Notification::make()
            ->success()
            ->title(__('capell-html-cache::admin.maintenance_site_updated'))
            ->send();
    }

    public function generateSite(int $siteId): void
    {
        $site = Site::query()->findOrFail($siteId);

        GenerateMaintenancePageCacheAction::run($site);

        Notification::make()
            ->success()
            ->title(__('capell-html-cache::admin.maintenance_cache_generated'))
            ->send();
    }

    /** @return array<int, Action> */
    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate-all')
                ->label(__('capell-html-cache::admin.generate_all_maintenance_pages'))
                ->icon('heroicon-o-arrow-path')
                ->authorize(fn (): bool => self::canManageMaintenance())
                ->action(function (): void {
                    GenerateAllMaintenancePageCachesAction::run();

                    Notification::make()
                        ->success()
                        ->title(__('capell-html-cache::admin.maintenance_cache_generated'))
                        ->send();
                }),
            Action::make('enable-global-maintenance')
                ->label(__('capell-html-cache::admin.enable_global_maintenance'))
                ->icon('heroicon-o-lock-closed')
                ->authorize(fn (): bool => self::canManageMaintenance())
                ->requiresConfirmation()
                ->action(function (): void {
                    $secret = Str::random(32);

                    GenerateAllMaintenancePageCachesAction::run();
                    resolve(MaintenanceManifestStore::class)->setGlobalActive(true);
                    Artisan::call('down', ['--secret' => $secret]);
                    Cookie::queue(MaintenanceModeBypassCookie::create($secret));

                    Notification::make()
                        ->success()
                        ->title(__('capell-html-cache::admin.global_maintenance_enabled'))
                        ->send();
                }),
            Action::make('disable-global-maintenance')
                ->label(__('capell-html-cache::admin.disable_global_maintenance'))
                ->icon('heroicon-o-lock-open')
                ->color('success')
                ->authorize(fn (): bool => self::canManageMaintenance())
                ->action(function (): void {
                    resolve(MaintenanceManifestStore::class)->setGlobalActive(false);
                    Artisan::call('up');

                    Notification::make()
                        ->success()
                        ->title(__('capell-html-cache::admin.global_maintenance_disabled'))
                        ->send();
                }),
        ];
    }

    private static function canManageMaintenance(): bool
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
