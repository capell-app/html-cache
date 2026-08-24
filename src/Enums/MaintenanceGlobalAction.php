<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Enums;

/**
 * The single applicable global action for the current MaintenanceCacheStatus.
 * The page renders at most one of these; it never offers enable and disable
 * as simultaneous peer actions.
 */
enum MaintenanceGlobalAction: string
{
    case Prepare = 'prepare';
    case ReviewAndEnable = 'review-and-enable';
    case ExitMaintenance = 'exit-maintenance';

    public function label(): string
    {
        return match ($this) {
            self::Prepare => __('capell-html-cache::admin.maintenance_global_action_prepare'),
            self::ReviewAndEnable => __('capell-html-cache::admin.maintenance_global_action_review_and_enable'),
            self::ExitMaintenance => __('capell-html-cache::admin.maintenance_global_action_exit_maintenance'),
        };
    }
}
