<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Admin;

use Capell\Admin\Contracts\AdminTools\AdminToolItem;
use Capell\HtmlCache\Filament\Pages\MaintenanceCachePage;
use Illuminate\Support\Facades\Blade;

final class MaintenanceAdminTool implements AdminToolItem
{
    public function render(): string
    {
        if (! MaintenanceCachePage::canAccess()) {
            return '';
        }

        return Blade::render(
            <<<'BLADE'
                <a
                    class="fi-dropdown-list-item fi-dropdown-list-item-color-gray flex w-full items-center gap-2 whitespace-nowrap rounded-md p-2 text-sm outline-none transition-colors duration-75 hover:bg-gray-50 focus:bg-gray-50 dark:hover:bg-white/5 dark:focus:bg-white/5"
                    href="{{ $url }}"
                >
                    @svg('heroicon-o-wrench-screwdriver', 'fi-dropdown-list-item-icon h-5 w-5 text-gray-400 dark:text-gray-500')
                    {{ __('capell-html-cache::admin.maintenance_cache') }}
                </a>
            BLADE,
            ['url' => MaintenanceCachePage::getUrl()],
        );
    }
}
