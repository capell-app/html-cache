<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Admin\Support\SiteScope;
use Capell\Core\Models\Site;
use Capell\Frontend\Support\Maintenance\MaintenanceManifestStore;
use Capell\HtmlCache\Models\HtmlCacheGenerationRun;
use Capell\HtmlCache\Support\Maintenance\MaintenanceCachePermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

/**
 * "Review and enable" global action. Only offered from the Ready state, but
 * re-reads the manifest and every accessible enabled site's readiness before
 * queuing: a concurrent manifest change (a site's generated output cleared,
 * or global maintenance already switched on by another admin) is caught here
 * rather than silently enabling on stale assumptions.
 */
final class EnableGlobalMaintenanceAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly MaintenanceManifestStore $manifestStore,
    ) {}

    public function handle(Authenticatable $actor): HtmlCacheGenerationRun
    {
        throw_unless(MaintenanceCachePermissions::canManageGlobal($actor), AuthorizationException::class);

        $manifest = $this->manifestStore->read();

        throw_if(($manifest['global_active'] ?? false) === true, RuntimeException::class, 'Global maintenance is already active.');

        $sites = SiteScope::applyForCurrentActor(Site::query()->enabled(), 'id', denyWhenMissingActor: true)
            ->with(['language', 'siteDomains.language', 'theme', 'translations'])
            ->ordered()
            ->get();

        throw_if($sites->isEmpty(), RuntimeException::class, 'No accessible sites are ready to enable maintenance.');

        $notReady = $sites->contains(
            fn (Site $site): bool => data_get($manifest, 'sites.' . $site->id . '.domains', []) === [],
        );

        throw_if($notReady, RuntimeException::class, 'All accessible sites must be prepared before enabling global maintenance.');

        $secret = Str::random(32);

        $run = QueueMaintenancePageGenerationAction::run($sites, enableGlobal: true, secret: $secret);

        Cookie::queue(MaintenanceModeBypassCookie::create($secret));

        return $run;
    }
}
