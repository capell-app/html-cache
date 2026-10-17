<?php

declare(strict_types=1);

use Capell\Core\Actions\InstallPackageAction;
use Capell\Core\Actions\RuntimeRefresh\RefreshInstalledPackageRuntimeAction;
use Capell\Core\Data\Runtime\RuntimeRoleSelectionData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Octane\Resettable;
use Capell\Core\Support\Runtime\RuntimeRoleResolver;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Observers\HtmlCacheModelInvalidationObserver;
use Capell\HtmlCache\Providers\HtmlCacheServiceProvider;
use Capell\Tests\Support\InstalledRuntimeParity;
use Capell\Tests\Support\InstalledRuntimeState;
use Capell\Tests\Support\InstalledRuntimeTestCase;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;

it('matches fresh installed boot after in-process installation and repeated refresh', function (): void {
    InstalledRuntimeParity::assertPackage('html-cache');
});

it('keeps maintenance schedules available in the public role after installation', function (): void {
    InstalledRuntimeState::run(static function (): void {
        $app = InstalledRuntimeTestCase::boot(InstalledRuntimeParity::manifest('html-cache'), false, static function (Application $app): void {
            $app->instance(RuntimeRoleResolver::class, new RuntimeRoleResolver(RuntimeRoleSelectionData::fromConfiguredValue('public')));
            $app->make(Repository::class)->set('capell-html-cache.invalidation.mode', 'scheduled');
        }, sourceRoot: dirname(__DIR__, 3));
        $schedule = $app->make(Schedule::class);
        $before = iterator_to_array($app->tagged(Resettable::TAG));
        $middleware = $app->make(Router::class)->getMiddleware();
        $package = CapellCore::getPackage('capell-app/html-cache');
        InstalledRuntimeTestCase::prepareLateInstallMigrations($app);
        InstallPackageAction::run($package);
        RefreshInstalledPackageRuntimeAction::run($package);
        RefreshInstalledPackageRuntimeAction::run($package);

        expect($app->make(Router::class)->getMiddleware()['frontend.cache'] ?? null)->toBe(HtmlCacheMiddleware::class)
            ->and($middleware['frontend.cache'] ?? null)->toBe(HtmlCacheMiddleware::class);
        expect(array_filter(iterator_to_array($app->tagged(Resettable::TAG)), static fn (mixed $service): bool => $service instanceof HtmlCacheModelInvalidationObserver))->toHaveCount(1)
            ->and(array_filter($before, static fn (mixed $service): bool => $service instanceof HtmlCacheModelInvalidationObserver))->toHaveCount(1);

        foreach (['capell:html-cache:process-stale'] as $command) {
            expect(array_filter($schedule->events(), static fn (ScheduledEvent $event): bool => is_string($event->command) && str_contains($event->command, $command)))->toHaveCount(1);
        }
    });
});

it('matches the enabled cache with scheduled invalidation', function (): void {
    $configure = require __DIR__ . '/Fixtures/cache-enabled.php';
    InstalledRuntimeParity::assertPackage('html-cache', static function (Application $app): void {
        expect(array_filter($app->make(Schedule::class)->events(), static fn (ScheduledEvent $event): bool => is_string($event->command) && str_contains($event->command, 'capell:html-cache:process-stale')))->toHaveCount(1);
    }, $configure);
});

it('registers commands when its provider loads after the console has started', function (): void {
    InstalledRuntimeState::run(static function (): void {
        $app = InstalledRuntimeTestCase::boot(InstalledRuntimeParity::manifest('html-cache'), false, includeTarget: false);
        $kernel = $app->make(Kernel::class);
        expect($kernel->all())->not->toHaveKey('capell:html-cache:diagnose');

        $app->register(HtmlCacheServiceProvider::class);

        expect($kernel->all())->toHaveKeys([
            'capell:html-cache:clear',
            'capell:html-cache:diagnose',
            'capell:html-cache:edge-purge:verify',
            'capell:html-cache:process-stale',
            'capell:static-site',
        ]);
    });
});
