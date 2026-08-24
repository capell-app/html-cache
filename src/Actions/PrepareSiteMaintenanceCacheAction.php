<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Core\Models\Site;
use Capell\HtmlCache\Models\HtmlCacheGenerationRun;
use Capell\HtmlCache\Support\Maintenance\MaintenanceCachePermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Per-site "Prepare" action: generates a maintenance page for one site. Only
 * offered on a row in the Missing state, but re-authorises and re-checks
 * scope regardless of what the button's visibility implied.
 */
final class PrepareSiteMaintenanceCacheAction
{
    use AsFake;
    use AsObject;

    public function handle(Authenticatable $actor, int $siteId): HtmlCacheGenerationRun
    {
        $site = Site::query()->findOrFail($siteId);

        throw_unless(MaintenanceCachePermissions::canManageSite($actor, $site), AuthorizationException::class);

        return QueueMaintenancePageGenerationAction::run(new Collection([$site]));
    }
}
