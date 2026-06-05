<?php

declare(strict_types=1);

it('keeps html cache marketplace and composer copy outcome-led and aligned', function (): void {
    $packagePath = dirname(__DIR__, 2);
    $manifest = htmlCacheJsonFile($packagePath . '/capell.json');
    $composer = htmlCacheJsonFile($packagePath . '/composer.json');

    $description = 'Full-page static HTML cache for Capell with dependency-indexed invalidation, scheduled stale-regeneration, and public-output safety guarantees.';
    $summary = 'Serve Capell pages as static HTML for sub-millisecond responses — with automatic, dependency-aware invalidation that keeps cached pages fresh and never leaks gated or authoring content to anonymous visitors.';

    expect($manifest['description'] ?? null)->toBe($description)
        ->and($composer['description'] ?? null)->toBe($description)
        ->and(data_get($manifest, 'marketplace.summary'))->toBe($summary)
        ->and(htmlCacheTextFile($packagePath . '/README.md'))->toContain($description)
        ->and(htmlCacheTextFile($packagePath . '/docs/README.md'))->toContain($description);
});

/**
 * @return array<string, mixed>
 */
function htmlCacheJsonFile(string $path): array
{
    return json_decode(htmlCacheTextFile($path), true, flags: JSON_THROW_ON_ERROR);
}

function htmlCacheTextFile(string $path): string
{
    return (string) file_get_contents($path);
}
