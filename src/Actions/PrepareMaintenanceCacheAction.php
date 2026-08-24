<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Admin\Support\SiteScope;
use Capell\Core\Models\Site;
use Capell\HtmlCache\Models\HtmlCacheGenerationRun;
use Capell\HtmlCache\Support\Maintenance\MaintenanceCachePermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

/**
 * "Prepare" global action: generates maintenance pages for every accessible
 * enabled site without touching the global maintenance flag. Re-authorises
 * and re-reads the accessible site set at call time rather than trusting
 * whatever the Livewire component last rendered.
 */
final class PrepareMaintenanceCacheAction
{
    use AsFake;
    use AsObject;

    public function handle(Authenticatable $actor): HtmlCacheGenerationRun
    {
        throw_unless(MaintenanceCachePermissions::canManage($actor), AuthorizationException::class);

        $sites = SiteScope::applyForCurrentActor(Site::query()->enabled(), 'id', denyWhenMissingActor: true)
            ->with(['language', 'siteDomains.language', 'theme', 'translations'])
            ->ordered()
            ->get();

        throw_if($sites->isEmpty(), RuntimeException::class, 'No accessible sites to prepare.');

        return QueueMaintenancePageGenerationAction::run($sites);
    }
}
