<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Enums;

/**
 * The single applicable action for one site's row. A row never offers enable
 * and disable together.
 */
enum MaintenanceSiteAction: string
{
    case Prepare = 'prepare';
    case EnableOverride = 'enable-override';
    case DisableOverride = 'disable-override';

    public function label(): string
    {
        return match ($this) {
            self::Prepare => __('capell-html-cache::admin.maintenance_site_action_prepare'),
            self::EnableOverride => __('capell-html-cache::admin.maintenance_site_action_enable_override'),
            self::DisableOverride => __('capell-html-cache::admin.maintenance_site_action_disable_override'),
        };
    }
}
