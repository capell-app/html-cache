<?php

declare(strict_types=1);

use Capell\HtmlCache\Enums\HtmlCacheEligibilityReason;
use Capell\HtmlCache\Support\Cache\PublicResponseCachePolicy;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Livewire\Component;
use Livewire\Features\SupportDisablingBackButtonCache\SupportDisablingBackButtonCache;
use Livewire\Livewire;

uses(HtmlCacheTestCase::class);

final class HtmlCacheIsolationComponent extends Component
{
    public function render(): string
    {
        return '<div>Livewire isolation render</div>';
    }
}

it('renders a Livewire component outside the HTTP kernel', function (): void {
    Livewire::component('html-cache-isolation', HtmlCacheIsolationComponent::class);

    expect(Livewire::mount('html-cache-isolation'))->toContain('Livewire isolation render')
        ->and(SupportDisablingBackButtonCache::$disableBackButtonCache)->toBeTrue();
});

it('preserves a private route policy in the next package application', function (): void {
    Route::get('/livewire-state-isolation', static fn (): Response => response(
        'Private origin response',
        200,
        ['Content-Type' => 'text/html', 'Cache-Control' => 'private'],
    ));

    $response = $this->get('/livewire-state-isolation');

    $response->assertOk()->assertContent('Private origin response')->assertHeader('Cache-Control', 'private');
    expect(resolve(PublicResponseCachePolicy::class)->reasons($response->baseResponse))
        ->toContain(HtmlCacheEligibilityReason::ResponsePrivate);
});
