<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Enums;

/**
 * Why the Maintenance cache page is in the Attention state. Multiple reasons
 * can apply at once; the projection reports every one that holds.
 */
enum MaintenanceCacheAttentionReason: string
{
    /** No site is accessible to the current actor, so nothing can be prepared. */
    case NoAccessibleSites = 'no_accessible_sites';

    /** None of the accessible enabled sites has a domain configured at all. */
    case NoSiteDomainsConfigured = 'no_site_domains_configured';

    /** The most recent generation run finished with at least one failed site. */
    case GenerationFailed = 'generation_failed';

    /** The manifest's global flag and Artisan's actual maintenance state disagree. */
    case ArtisanStateDrift = 'artisan_state_drift';

    public function label(): string
    {
        return __('capell-html-cache::admin.maintenance_attention_' . $this->value);
    }
}
