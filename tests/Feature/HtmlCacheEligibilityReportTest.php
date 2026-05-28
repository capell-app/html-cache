<?php

declare(strict_types=1);

use Capell\Frontend\Actions\Performance\RecordExtensionRenderContributionAction;
use Capell\HtmlCache\Actions\BuildHtmlCacheEligibilityReportAction;
use Capell\HtmlCache\Console\Commands\DiagnoseHtmlCacheCommand;
use Capell\HtmlCache\Enums\HtmlCacheEligibilityReason;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Http\Request;

uses(HtmlCacheTestCase::class);

beforeEach(function (): void {
    config()->set('capell-html-cache.enabled', true);
    config()->set('capell-html-cache.write_enabled', true);
    config()->set('session.cookie', 'capell_session');
});

it('reports stable request and package cache eligibility reason codes', function (): void {
    $request = Request::create('https://example.test/contact?signature=signed', 'POST');
    $request->cookies->set('capell_session', 'session-value');
    $request->headers->set('X-Livewire', 'true');
    $request->headers->set('Authorization', 'Bearer token');
    $request->setUserResolver(fn (): User => User::factory()->create());
    app()->instance('request', $request);

    RecordExtensionRenderContributionAction::run(
        packageName: 'capell-app/form-builder',
        surface: 'frontend',
        contributionType: 'frontend-component',
        contributionClass: 'Capell\\FormBuilder\\Livewire\\FormElementComponent',
        elapsedMilliseconds: 10,
        frontendRenderBudgetMs: 20,
        cacheTags: ['form-builder'],
        cacheable: false,
        sensitiveOutput: true,
        variesBy: [],
    );

    $report = BuildHtmlCacheEligibilityReportAction::run($request);

    expect($report->eligible)->toBeFalse()
        ->and($report->hasReason(HtmlCacheEligibilityReason::NonGetRequest))->toBeTrue()
        ->and($report->hasReason(HtmlCacheEligibilityReason::SignedPreviewRequest))->toBeTrue()
        ->and($report->hasReason(HtmlCacheEligibilityReason::AuthenticatedOrSessionRequest))->toBeTrue()
        ->and($report->hasReason(HtmlCacheEligibilityReason::LivewireRequest))->toBeTrue()
        ->and($report->hasReason(HtmlCacheEligibilityReason::AuthorizationHeaderPresent))->toBeTrue()
        ->and($report->hasReason(HtmlCacheEligibilityReason::PackageCacheBlocking))->toBeTrue()
        ->and($report->hasReason(HtmlCacheEligibilityReason::PackageSensitiveOutput))->toBeTrue()
        ->and($report->blockingPackages)->toBe(['capell-app/form-builder'])
        ->and($report->cacheTags)->toBe(['form-builder']);
});

it('stores eligibility reports on middleware requests without exposing detailed public headers', function (): void {
    $request = Request::create('https://example.test/about?preview=1', 'GET');
    app()->instance('request', $request);

    $response = resolve(HtmlCacheMiddleware::class)->handle(
        $request,
        fn () => response('<main>Preview bypass</main>', 200, ['Content-Type' => 'text/html']),
    );

    $report = $request->attributes->get(HtmlCacheMiddleware::ELIGIBILITY_REPORT_ATTRIBUTE);

    expect($report)->not->toBeNull()
        ->and($report->reasonCodes())->toContain('query_string_present')
        ->and($response->headers->get('X-Frontend-Cache'))->toBe('MISS')
        ->and($response->headers->has('X-Capell-Cache-Reasons'))->toBeFalse();
});

it('reports invalid stale refresh claims', function (): void {
    $request = Request::create('https://example.test/about', 'GET');
    $request->attributes->set(HtmlCacheMiddleware::STALE_CACHE_ID_ATTRIBUTE, 123);
    $request->attributes->set(HtmlCacheMiddleware::STALE_CACHE_CLAIM_TOKEN_ATTRIBUTE, 'missing-token');

    $report = BuildHtmlCacheEligibilityReportAction::run($request);

    expect($report->hasReason(HtmlCacheEligibilityReason::StaleClaimInvalid))->toBeTrue();
});

it('renders the diagnose command as json', function (): void {
    $this->artisan(DiagnoseHtmlCacheCommand::class, [
        'url' => 'https://example.test/about',
        '--json' => true,
    ])
        ->expectsOutputToContain('"url": "https:\\/\\/example.test\\/about"')
        ->assertExitCode(0);
});
