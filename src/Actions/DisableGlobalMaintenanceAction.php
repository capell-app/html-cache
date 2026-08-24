<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Frontend\Support\Maintenance\MaintenanceManifestStore;
use Capell\HtmlCache\Data\EdgeCachePurgeData;
use Capell\HtmlCache\Support\Maintenance\MaintenanceCachePermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Artisan;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * "Exit maintenance" global action. This is also the recovery action from
 * Attention when the manifest and Artisan's actual maintenance state have
 * drifted apart (for example, someone ran `php artisan down`/`up` directly):
 * it always resyncs Artisan to "up" whenever it reports down, and always
 * clears the manifest flag, regardless of which side was out of step.
 * Idempotent when neither side needed changing.
 */
final class DisableGlobalMaintenanceAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly MaintenanceManifestStore $manifestStore,
    ) {}

    public function handle(Authenticatable $actor): void
    {
        throw_unless(MaintenanceCachePermissions::canManageGlobal($actor), AuthorizationException::class);

        $manifest = $this->manifestStore->read();
        $manifestWasActive = ($manifest['global_active'] ?? false) === true;
        $artisanWasDown = app()->maintenanceMode()->active();

        if (! $manifestWasActive && ! $artisanWasDown) {
            return;
        }

        if ($manifestWasActive) {
            $this->manifestStore->setGlobalActive(false);
        }

        if ($artisanWasDown) {
            Artisan::call('up');
        }

        PurgeEdgeCacheAction::dispatchAfterCommit(new EdgeCachePurgeData(purgeAll: true));
    }
}
