<?php

declare(strict_types=1);

use Capell\FormBuilder\Livewire\FormElementComponent;
use Capell\Frontend\Actions\Performance\RecordExtensionRenderContributionAction;
use Capell\HtmlCache\Actions\BuildHtmlCacheEligibilityReportAction;
use Capell\HtmlCache\Enums\HtmlCacheEligibilityReason;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Cookie;

uses(HtmlCacheTestCase::class);

beforeEach(function (): void {
    config()->set('capell-html-cache.enabled', true);
    config()->set('capell-html-cache.write_enabled', true);
    config()->set('session.cookie', 'capell_session');
});

it('reports stable request and package cache eligibility reason codes', function (): void {
    $request = Request::create('https://example.test/contact?signature=signed', Symfony\Component\HttpFoundation\Request::METHOD_POST);
    $request->cookies->set('capell_session', 'session-value');
    $request->headers->set('X-Livewire', 'true');
    $request->headers->set('Authorization', 'Bearer token');
    $request->setUserResolver(fn (): User => User::factory()->create());

    app()->instance('request', $request);

    RecordExtensionRenderContributionAction::run(
        packageName: 'capell-app/form-builder',
        surface: 'frontend',
        contributionType: 'frontend-component',
        contributionClass: FormElementComponent::class,
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
    $request = Request::create('https://example.test/about?preview=1', Symfony\Component\HttpFoundation\Request::METHOD_GET);
    app()->instance('request', $request);

    $response = resolve(HtmlCacheMiddleware::class)->handle(
        $request,
        fn (): ResponseFactory|Response => response('<main>Preview bypass</main>', 200, ['Content-Type' => 'text/html']),
    );

    $report = $request->attributes->get(HtmlCacheMiddleware::ELIGIBILITY_REPORT_ATTRIBUTE);

    expect($report)->not->toBeNull()
        ->and($report->reasonCodes())->toContain('query_string_present')
        ->and($response->headers->get('X-Frontend-Cache'))->toBeNull()
        ->and($response->headers->has('X-Capell-Cache-Reasons'))->toBeFalse();
});

it('reports invalid stale refresh claims', function (): void {
    $request = Request::create('https://example.test/about', Symfony\Component\HttpFoundation\Request::METHOD_GET);
    $request->attributes->set(HtmlCacheMiddleware::STALE_CACHE_ID_ATTRIBUTE, 123);
    $request->attributes->set(HtmlCacheMiddleware::STALE_CACHE_CLAIM_TOKEN_ATTRIBUTE, 'missing-token');

    $report = BuildHtmlCacheEligibilityReportAction::run($request);

    expect($report->hasReason(HtmlCacheEligibilityReason::StaleClaimInvalid))->toBeTrue();
});

it('rejects response directives and state that are unsafe for a shared cache', function (Response $response, HtmlCacheEligibilityReason $reason): void {
    $request = Request::create('https://example.test/about', Symfony\Component\HttpFoundation\Request::METHOD_GET);
    app()->instance('request', $request);

    $report = BuildHtmlCacheEligibilityReportAction::run($request, $response);

    expect($report->eligible)->toBeFalse()
        ->and($report->hasReason($reason))->toBeTrue();
})->with([
    'private' => [fn (): Response => response('private', 200, ['Content-Type' => 'text/html', 'Cache-Control' => 'private, max-age=0']), HtmlCacheEligibilityReason::ResponsePrivate],
    'no cache' => [fn (): Response => response('fresh', 200, ['Content-Type' => 'text/html', 'Cache-Control' => 'no-cache, max-age=30']), HtmlCacheEligibilityReason::ResponseNoCache],
    'unsupported vary' => [fn (): Response => response('varied', 200, ['Content-Type' => 'text/html', 'Vary' => 'Accept-Language']), HtmlCacheEligibilityReason::UnsupportedVaryHeader],
    'remaining cookie' => [function (): Response {
        $response = response('cookie', 200, ['Content-Type' => 'text/html']);
        $response->headers->setCookie(new Cookie('personalisation', 'one'));

        return $response;
    }, HtmlCacheEligibilityReason::ResponseSetsCookie],
]);

it('never writes authenticated responses even when authenticated cache reads are enabled', function (): void {
    config()->set('capell-html-cache.cache_skip_authenticated', false);

    $request = Request::create('https://example.test/account', Symfony\Component\HttpFoundation\Request::METHOD_GET);
    $request->cookies->set('capell_session', 'session-value');
    $request->setUserResolver(fn (): User => User::factory()->create());
    app()->instance('request', $request);

    $report = BuildHtmlCacheEligibilityReportAction::run(
        $request,
        response('personalised', 200, ['Content-Type' => 'text/html']),
    );

    expect($report->hasReason(HtmlCacheEligibilityReason::AuthenticatedOrSessionRequest))->toBeTrue();
});

it('renders the diagnose command as json', function (): void {
    $this->artisan('capell:html-cache:diagnose', [
        'url' => 'https://example.test/about',
        '--json' => true,
    ])
        ->expectsOutputToContain('"url": "https:\\/\\/example.test\\/about"')
        ->assertExitCode(0);
});

it('can diagnose the rendered response contract', function (): void {
    Route::get('/diagnostic-response', fn (): Response => response(
        'private response',
        200,
        ['Content-Type' => 'text/html', 'Cache-Control' => 'private, max-age=30', 'Vary' => 'Accept-Language'],
    ));

    $this->artisan('capell:html-cache:diagnose', [
        'url' => '/diagnostic-response',
        '--render' => true,
        '--json' => true,
    ])
        ->expectsOutputToContain('"response": {')
        ->assertSuccessful();
});
