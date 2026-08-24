<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Core\Models\Site;
use Capell\Frontend\Support\Maintenance\MaintenanceManifestStore;
use Capell\HtmlCache\Data\EdgeCachePurgeData;
use Capell\HtmlCache\Support\Maintenance\MaintenanceCachePermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Per-site "Disable override" action. Idempotent: if the override is already
 * off (for example, disabled a moment ago by another admin), this is a no-op
 * rather than a redundant purge.
 */
final class DisableSiteMaintenanceOverrideAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly MaintenanceManifestStore $manifestStore,
    ) {}

    public function handle(Authenticatable $actor, int $siteId): void
    {
        $site = Site::query()->findOrFail($siteId);

        throw_unless(MaintenanceCachePermissions::canManageSite($actor, $site), AuthorizationException::class);

        $manifest = $this->manifestStore->read();

        if (data_get($manifest, 'sites.' . $siteId . '.active') !== true) {
            return;
        }

        $this->manifestStore->setSiteActive($siteId, false);
        PurgeEdgeCacheAction::dispatchAfterCommit(new EdgeCachePurgeData(tags: ['site-' . $siteId]));
    }
}
