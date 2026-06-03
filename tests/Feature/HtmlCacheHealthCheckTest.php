<?php

declare(strict_types=1);

use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\HtmlCache\Health\HtmlCacheHealthCheck;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

uses(HtmlCacheTestCase::class);

it('passes all diagnostics when the cache is fully wired', function (): void {
    Storage::fake('page_cache');
    config()->set('capell-html-cache.invalidation.mode', 'instant');

    $results = HtmlCacheHealthCheck::runDiagnostics();

    expect($results)->toHaveCount(4)
        ->and($results->every(static fn (DoctorCheckResultData $result): bool => $result->passed))->toBeTrue()
        ->and(HtmlCacheHealthCheck::passed())->toBeTrue();
});

it('reports the disk writable, middleware wired, and tables present checks individually', function (): void {
    Storage::fake('page_cache');

    $check = new HtmlCacheHealthCheck;

    expect($check->isPageCacheDiskWritable())->toBeTrue()
        ->and($check->isFrontendCacheMiddlewareWired())->toBeTrue()
        ->and($check->missingTables())->toBe([]);
});

it('fails the storage tables diagnostic when an html cache table is missing', function (): void {
    Storage::fake('page_cache');

    Schema::drop('stale_cached_urls');

    $check = new HtmlCacheHealthCheck;
    $result = $check->storageTablesCheck();

    expect($result->passed)->toBeFalse()
        ->and($result->message)->toContain('stale_cached_urls')
        ->and($result->remediation)->not->toBeNull()
        ->and(HtmlCacheHealthCheck::passed())->toBeFalse();
});

it('fails the middleware diagnostic when the frontend.cache alias is not wired', function (): void {
    Storage::fake('page_cache');

    Route::aliasMiddleware('frontend.cache', stdClass::class);

    $check = new HtmlCacheHealthCheck;
    $result = $check->frontendCacheMiddlewareWiredCheck();

    expect($result->passed)->toBeFalse()
        ->and($result->remediation)->not->toBeNull();

    Route::aliasMiddleware('frontend.cache', HtmlCacheMiddleware::class);
});

it('does not require the stale command while invalidation mode is instant', function (): void {
    config()->set('capell-html-cache.invalidation.mode', 'instant');

    $result = (new HtmlCacheHealthCheck)->staleProcessingCommandRegisteredCheck();

    expect($result->passed)->toBeTrue()
        ->and($result->message)->toContain('instant');
});

it('passes the stale command diagnostic while invalidation mode is scheduled and the command is registered', function (): void {
    config()->set('capell-html-cache.invalidation.mode', 'scheduled');

    $check = new HtmlCacheHealthCheck;

    expect($check->isStaleProcessingCommandRegistered())->toBeTrue()
        ->and($check->staleProcessingCommandRegisteredCheck()->passed)->toBeTrue();
});
