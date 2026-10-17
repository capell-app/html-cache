<?php

declare(strict_types=1);

use Capell\Core\Models\PageUrl;
use Capell\Core\Models\SiteDomain;
use Capell\HtmlCache\Support\Telemetry\HtmlCacheRequestObservationStore;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Capell\Tests\Support\RunMigration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(HtmlCacheTestCase::class);

beforeEach(function (): void {
    if (! Schema::hasTable(HtmlCacheRequestObservationStore::TABLE)) {
        RunMigration::up(dirname(__DIR__, 2) . '/database/migrations/2026_10_12_000001_create_html_cache_request_observations_table.php');
    }
});

it('keeps a shared weighted observation window and preserves the last render on a hit', function (): void {
    $store = resolve(HtmlCacheRequestObservationStore::class);
    $hash = hash('sha256', 'https://observed.test/page');
    $store->record($hash, 1, 1, 120.25, 10);
    $first = $store->read($hash, 1, 1);
    $store->record($hash, 1, 1, null, 30);
    $observed = $store->read($hash, 1, 1);

    expect($first?->hitRate)->toBe(0.0)
        ->and($observed?->lastRenderMilliseconds)->toBe(120.25)
        ->and($observed?->weightedHits)->toBe(30)
        ->and($observed?->weightedMisses)->toBe(10)
        ->and($observed?->hitRate)->toBe(75.0)
        ->and($observed?->observedSince)->toBe($first?->observedSince)
        ->and(DB::table(HtmlCacheRequestObservationStore::TABLE)->count())->toBe(1);
});

it('isolates the same URL hash across sites and languages', function (): void {
    $store = resolve(HtmlCacheRequestObservationStore::class);
    $hash = hash('sha256', 'https://observed.test/page');
    $store->record($hash, 1, 1, 120.25, 1);
    $store->record($hash, 2, 1, 40.0, 1);
    $store->record($hash, 1, 2, null, 1);

    expect($store->read($hash, 1, 1)?->lastRenderMilliseconds)->toBe(120.25)
        ->and($store->read($hash, 2, 1)?->lastRenderMilliseconds)->toBe(40.0)
        ->and($store->read($hash, 1, 2)?->hitRate)->toBe(100.0)
        ->and($store->read($hash, 1, 2)?->lastRenderMilliseconds)->toBeNull()
        ->and($store->read($hash, 2, 2))->toBeNull()
        ->and(DB::table(HtmlCacheRequestObservationStore::TABLE)->count())->toBe(3);
});

it('leaves metrics unavailable before the owned migration is applied', function (): void {
    $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_10_12_000001_create_html_cache_request_observations_table.php';
    $down = [$migration, 'down'];
    if (! is_callable($down)) {
        throw new RuntimeException('The observation migration must support rollback.');
    }

    $down();
    $store = resolve(HtmlCacheRequestObservationStore::class);
    $hash = hash('sha256', 'https://observed.test/page');
    $store->record($hash, 1, 1, 120.25, 1);

    expect($store->read($hash, 1, 1))->toBeNull()
        ->and(Schema::hasTable(HtmlCacheRequestObservationStore::TABLE))->toBeFalse();
});

it('shares wildcard request aliases but starts a new identity when the configured route changes', function (): void {
    $domain = new SiteDomain(['scheme' => null, 'domain' => null, 'path' => null]);
    $domain->setAttribute('id', 10);
    $domain->syncOriginal();

    $pageUrl = new PageUrl(['url' => '/observed']);
    $pageUrl->setAttribute('id', 20);
    $pageUrl->setRelation('siteDomain', $domain);

    $configuredHash = HtmlCacheRequestObservationStore::pageUrlHash($pageUrl);
    $requestDomain = clone $domain;
    $requestDomain->setAttribute('domain', 'visitor-alias.test');
    $requestDomain->setAttribute('scheme', 'https');

    $pageUrl->setRelation('siteDomain', $requestDomain);

    expect(HtmlCacheRequestObservationStore::pageUrlHash($pageUrl))->toBe($configuredHash);
    $pageUrl->url = '/changed';
    expect(HtmlCacheRequestObservationStore::pageUrlHash($pageUrl))->not->toBe($configuredHash);
});
