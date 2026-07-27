<?php

declare(strict_types=1);

use Capell\HtmlCache\Actions\InspectEdgeCachePurgeReadinessAction;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;

uses(HtmlCacheTestCase::class);

it('reports a ready Cloudflare publish-time purge configuration without a network request', function (): void {
    config()->set('capell-html-cache.purge.driver', 'cloudflare');
    config()->set('capell-html-cache.purge.required', true);
    config()->set('capell-html-cache.purge.cloudflare.zone_id', '0123456789abcdef0123456789abcdef');
    config()->set('capell-html-cache.purge.token', 'cloudflare-token');

    $readiness = InspectEdgeCachePurgeReadinessAction::run('cloudflare');

    expect($readiness->isReady())->toBeTrue()
        ->and($readiness->driver)->toBe('cloudflare')
        ->and($readiness->required)->toBeTrue()
        ->and($readiness->errors)->toBe([]);
});

it('reports every fail-closed Cloudflare configuration defect', function (): void {
    config()->set('capell-html-cache.purge.driver', 'null');
    config()->set('capell-html-cache.purge.required', true);

    $readiness = InspectEdgeCachePurgeReadinessAction::run('cloudflare');

    expect($readiness->isReady())->toBeFalse()
        ->and($readiness->errors)->toContain(
            'Expected edge purge driver [cloudflare], configured [null].',
            'Edge purge is required, but the null driver is configured.',
        );
});

it('exposes a non-mutating deployment verification command', function (): void {
    config()->set('capell-html-cache.purge.driver', 'cloudflare');
    config()->set('capell-html-cache.purge.required', true);
    config()->set('capell-html-cache.purge.cloudflare.zone_id', '0123456789abcdef0123456789abcdef');
    config()->set('capell-html-cache.purge.token', 'cloudflare-token');

    $this->artisan('capell:html-cache:edge-purge:verify', ['--driver' => 'cloudflare'])
        ->expectsOutputToContain('No purge request was sent.')
        ->assertSuccessful();
});

it('fails deployment verification when Cloudflare credentials are incomplete', function (): void {
    config()->set('capell-html-cache.purge.driver', 'cloudflare');
    config()->set('capell-html-cache.purge.required', true);
    config()->set('capell-html-cache.purge.cloudflare.zone_id');
    config()->set('capell-html-cache.purge.token');

    $this->artisan('capell:html-cache:edge-purge:verify', ['--driver' => 'cloudflare'])
        ->expectsOutputToContain('requires a 32-character hexadecimal zone ID')
        ->expectsOutputToContain('requires a non-empty API token')
        ->assertFailed();
});
