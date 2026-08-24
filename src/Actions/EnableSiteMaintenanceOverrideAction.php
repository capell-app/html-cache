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
use RuntimeException;

/**
 * Per-site "Enable override" action. Only offered on a row already in the
 * Generated state, but re-reads the manifest before writing: if the site's
 * generated output was cleared out from under this request (a concurrent
 * manifest change), the precondition is enforced here too, not only in the
 * UI.
 */
final class EnableSiteMaintenanceOverrideAction
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
        $domains = data_get($manifest, 'sites.' . $siteId . '.domains', []);

        throw_if(
            $domains === [],
            RuntimeException::class,
            'This site has no generated maintenance page yet. Prepare it before enabling its override.',
        );

        if (data_get($manifest, 'sites.' . $siteId . '.active') === true) {
            return;
        }

        $this->manifestStore->setSiteActive($siteId, true);
        PurgeEdgeCacheAction::dispatchAfterCommit(new EdgeCachePurgeData(tags: ['site-' . $siteId]));
    }
}
