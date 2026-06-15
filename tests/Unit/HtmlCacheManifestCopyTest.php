<?php

declare(strict_types=1);

use Capell\Core\Contracts\Extensions\ExtensionContribution;
use Capell\Core\Contracts\Extensions\RegistersExtensionRoute;
use Capell\Core\Contracts\Extensions\RegistersExtensionWidget;
use Capell\Core\Contracts\Extensions\RunsScheduledExtensionJob;
use Capell\HtmlCache\Filament\Pages\MaintenanceCachePage;
use Capell\HtmlCache\Filament\Widgets\CacheCoverageUrlsWidget;
use Capell\HtmlCache\Filament\Widgets\HtmlCacheOverviewWidget;
use Capell\HtmlCache\Filament\Widgets\HtmlCacheStaleQueueWidget;
use Capell\HtmlCache\Health\HtmlCacheHealthCheck;
use Capell\HtmlCache\Manifest\HtmlCacheAdminPagesContribution;
use Capell\HtmlCache\Manifest\HtmlCacheDashboardWidgetsContribution;
use Capell\HtmlCache\Manifest\HtmlCacheFrontendRoutesContribution;
use Capell\HtmlCache\Manifest\HtmlCacheModelsContribution;
use Capell\HtmlCache\Manifest\HtmlCacheStaleProcessingScheduleContribution;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Models\StaleCachedUrl;

it('keeps html cache marketplace and composer copy outcome-led and aligned', function (): void {
    $packagePath = dirname(__DIR__, 2);
    $manifest = htmlCacheJsonFile($packagePath . '/capell.json');
    $composer = htmlCacheJsonFile($packagePath . '/composer.json');

    $description = 'Full-page static HTML cache for Capell with dependency-indexed invalidation, scheduled stale-regeneration, and public-output safety guarantees.';
    $summary = 'Serve Capell pages as static HTML for sub-millisecond responses — with automatic, dependency-aware invalidation that keeps cached pages fresh and never leaks gated or authoring content to anonymous visitors.';

    expect($manifest['description'] ?? null)->toBe($description)
        ->and($composer['description'] ?? null)->toBe($description)
        ->and(data_get($manifest, 'marketplace.summary'))->toBe($summary)
        ->and(data_get($manifest, 'capabilities'))->toContain('full-page-cache', 'cache-blocking', 'surrogate-key-purge', 'cache-hit-telemetry', 'origin-stale-while-revalidate')
        ->and(data_get($manifest, 'performance.cacheSafety.cacheable'))->toBeTrue()
        ->and(htmlCacheTextFile($packagePath . '/README.md'))->toContain($description)
        ->and(htmlCacheTextFile($packagePath . '/docs/README.md'))->toContain($description);
});

it('registers the real html cache health check with diagnostics', function (): void {
    $packagePath = dirname(__DIR__, 2);
    $manifest = htmlCacheJsonFile($packagePath . '/capell.json');
    $healthChecks = $manifest['healthChecks'] ?? [];
    throw_unless(is_array($healthChecks), RuntimeException::class, 'Expected html cache health checks manifest data.');

    $healthCheck = collect($healthChecks)->firstWhere('key', 'html-cache.package-health');

    expect($healthCheck)->toBeArray();
    throw_unless(is_array($healthCheck), RuntimeException::class, 'Expected html cache health check manifest data.');

    expect($healthCheck['class'] ?? null)->toBe(HtmlCacheHealthCheck::class)
        ->and($healthCheck['severity'] ?? null)->toBe('critical')
        ->and($healthCheck['surface'] ?? null)->toBe('admin');
});

it('declares shipped html cache extension contributions instead of deferring them', function (): void {
    $manifest = htmlCacheJsonFile(dirname(__DIR__, 2) . '/capell.json');
    $contributions = collect($manifest['contributes'] ?? []);

    $adminPage = $contributions->firstWhere('class', HtmlCacheAdminPagesContribution::class);
    $dashboardWidgets = $contributions->firstWhere('class', HtmlCacheDashboardWidgetsContribution::class);
    $models = $contributions->firstWhere('class', HtmlCacheModelsContribution::class);
    $routes = $contributions->firstWhere('class', HtmlCacheFrontendRoutesContribution::class);
    $scheduledJob = $contributions->firstWhere('class', HtmlCacheStaleProcessingScheduleContribution::class);

    expect($manifest['contributionTraceability']['deferredContributions'] ?? null)->toBe([])
        ->and($adminPage)->toBeArray()
        ->and($adminPage['type'] ?? null)->toBe('admin-page')
        ->and($adminPage['pageClass'] ?? null)->toBe(MaintenanceCachePage::class)
        ->and($dashboardWidgets)->toBeArray()
        ->and($dashboardWidgets['type'] ?? null)->toBe('dashboard-widget')
        ->and($dashboardWidgets['widgetClasses'] ?? null)->toBe([
            HtmlCacheOverviewWidget::class,
            CacheCoverageUrlsWidget::class,
            HtmlCacheStaleQueueWidget::class,
        ])
        ->and($models)->toBeArray()
        ->and($models['type'] ?? null)->toBe('model')
        ->and($models['modelClasses'] ?? null)->toBe([
            CachedModelUrl::class,
            StaleCachedUrl::class,
        ])
        ->and($models['tables'] ?? null)->toBe([
            'cached_model_urls',
            'stale_cached_urls',
        ])
        ->and($routes)->toBeArray()
        ->and($routes['type'] ?? null)->toBe('route')
        ->and($routes['routes'] ?? null)->toBe($manifest['security']['publicSurface']['routeNames'] ?? null)
        ->and($routes['middlewareAliases'] ?? null)->toContain(
            'frontend.cache',
            'frontend.model_events',
            'frontend.no_session_cookies_on_cache',
        )
        ->and($scheduledJob)->toBeArray()
        ->and($scheduledJob['type'] ?? null)->toBe('scheduled-job')
        ->and($scheduledJob['command'] ?? null)->toBe('capell:html-cache:process-stale')
        ->and($scheduledJob['frequencyConfig'] ?? null)->toBe('capell-html-cache.invalidation.schedule')
        ->and($scheduledJob['enabledWhen'] ?? null)->toBe('capell-html-cache.invalidation.mode=scheduled')
        ->and(HtmlCacheAdminPagesContribution::compatibleCapellApiVersion())->toBe('^4.0')
        ->and(HtmlCacheDashboardWidgetsContribution::compatibleCapellApiVersion())->toBe('^4.0')
        ->and(HtmlCacheModelsContribution::compatibleCapellApiVersion())->toBe('^4.0')
        ->and(HtmlCacheFrontendRoutesContribution::compatibleCapellApiVersion())->toBe('^4.0')
        ->and(HtmlCacheStaleProcessingScheduleContribution::compatibleCapellApiVersion())->toBe('^4.0')
        ->and(class_implements(HtmlCacheAdminPagesContribution::class))->toContain(ExtensionContribution::class)
        ->and(class_implements(HtmlCacheDashboardWidgetsContribution::class))->toContain(RegistersExtensionWidget::class)
        ->and(class_implements(HtmlCacheModelsContribution::class))->toContain(ExtensionContribution::class)
        ->and(class_implements(HtmlCacheFrontendRoutesContribution::class))->toContain(RegistersExtensionRoute::class)
        ->and(class_implements(HtmlCacheStaleProcessingScheduleContribution::class))->toContain(RunsScheduledExtensionJob::class);
});

it('exposes package-local verification script aliases', function (): void {
    $composer = htmlCacheJsonFile(dirname(__DIR__, 2) . '/composer.json');

    expect($composer['scripts']['test'] ?? null)->toBe('../../vendor/bin/pest tests --configuration=../../phpunit.xml')
        ->and($composer['scripts']['lint'] ?? null)->toBe('../../vendor/bin/pint --config=../../pint.json')
        ->and($composer['scripts']['analyse'] ?? null)->toBe('cd ../.. && COMPOSER=composer.local.json composer analyze');
});

/**
 * @return array<string, mixed>
 */
function htmlCacheJsonFile(string $path): array
{
    $decoded = json_decode(htmlCacheTextFile($path), true, flags: JSON_THROW_ON_ERROR);
    throw_unless(is_array($decoded), RuntimeException::class, 'Expected JSON object or array.');

    /** @var array<string, mixed> $decoded */
    return $decoded;
}

function htmlCacheTextFile(string $path): string
{
    return (string) file_get_contents($path);
}
