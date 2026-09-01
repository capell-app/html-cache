<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Enums;

/**
 * The five explicit states the Maintenance cache page can present. Every
 * render resolves to exactly one of these so the operator never sees an
 * ambiguous mix of "enable" and "disable" framed as equally valid actions.
 */
enum MaintenanceCacheStatus: string
{
    case Off = 'off';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Active = 'active';
    case Attention = 'attention';

    public function label(): string
    {
        return match ($this) {
            self::Off => __('capell-html-cache::admin.maintenance_status_off'),
            self::Preparing => __('capell-html-cache::admin.maintenance_status_preparing'),
            self::Ready => __('capell-html-cache::admin.maintenance_status_ready'),
            self::Active => __('capell-html-cache::admin.maintenance_status_active'),
            self::Attention => __('capell-html-cache::admin.maintenance_status_attention'),
        };
    }
}
