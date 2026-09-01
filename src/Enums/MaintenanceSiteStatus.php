<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Enums;

/**
 * The state of one site's row on the Maintenance cache page.
 */
enum MaintenanceSiteStatus: string
{
    /** No maintenance page has ever been generated for this site. */
    case Missing = 'missing';

    /** A generation run currently targets this site. */
    case Preparing = 'preparing';

    /** A maintenance page exists but the site override is off. */
    case Generated = 'generated';

    /** The site-level override is on and global maintenance is off. */
    case Active = 'active';

    /** Global maintenance is on, so this site is down regardless of its own override. */
    case CoveredByGlobal = 'covered_by_global';

    public function label(): string
    {
        return match ($this) {
            self::Missing => __('capell-html-cache::admin.maintenance_site_status_missing'),
            self::Preparing => __('capell-html-cache::admin.maintenance_site_status_preparing'),
            self::Generated => __('capell-html-cache::admin.maintenance_site_status_generated'),
            self::Active => __('capell-html-cache::admin.maintenance_site_status_active'),
            self::CoveredByGlobal => __('capell-html-cache::admin.maintenance_site_status_covered_by_global'),
        };
    }
}
