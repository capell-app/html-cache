<?php

declare(strict_types=1);

use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Capell\Tests\Support\ScreenshotManifest;
use Illuminate\View\Factory;

uses(HtmlCacheTestCase::class);

it('serves the real maintenance template separately from the cached public homepage', function (): void {
    require __DIR__ . '/../../workbench/routes/screenshot-fixtures.php';
    $entry = ScreenshotManifest::entry(__DIR__ . '/../../docs/screenshots.json', 'html-cache-maintenance-page');
    expect($entry['url'])->toBe('/screenshot-fixtures/html-cache/maintenance');
    $response = $this->get('/screenshot-fixtures/html-cache/maintenance')->assertStatus(503);
    $views = app(Factory::class);
    $template = $views->getFinder()->find('errors::503');
    $response->assertContent($views->file($template)->render())
        ->assertDontSee('wire:id', false)
        ->assertDontSee('data-capell-authoring', false);
});
