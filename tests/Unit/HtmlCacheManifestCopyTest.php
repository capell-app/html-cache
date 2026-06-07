<?php

declare(strict_types=1);

use Capell\HtmlCache\Health\HtmlCacheHealthCheck;

it('keeps html cache marketplace and composer copy outcome-led and aligned', function (): void {
    $packagePath = dirname(__DIR__, 2);
    $manifest = htmlCacheJsonFile($packagePath . '/capell.json');
    $composer = htmlCacheJsonFile($packagePath . '/composer.json');

    $description = 'Full-page static HTML cache for Capell with dependency-indexed invalidation, scheduled stale-regeneration, and public-output safety guarantees.';
    $summary = 'Serve Capell pages as static HTML for sub-millisecond responses — with automatic, dependency-aware invalidation that keeps cached pages fresh and never leaks gated or authoring content to anonymous visitors.';

    expect($manifest['description'] ?? null)->toBe($description)
        ->and($composer['description'] ?? null)->toBe($description)
        ->and(data_get($manifest, 'marketplace.summary'))->toBe($summary)
        ->and(data_get($manifest, 'capabilities'))->toContain('full-page-cache', 'cache-blocking', 'surrogate-key-purge')
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
