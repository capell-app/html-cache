<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\SiteDomain;
use Capell\HtmlCache\Actions\BuildHtmlCacheEligibilityReportAction;
use Capell\HtmlCache\Actions\RecordHtmlCacheRequestObservationAction;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Support\Telemetry\HtmlCacheRequestObservationStore;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Lottery;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

uses(HtmlCacheTestCase::class);

beforeEach(function (): void {
    config(['capell-html-cache.enabled' => false, 'capell-html-cache.observation_recording.sample_rate' => 1]);
    if (! Schema::hasTable('html_cache_request_observations')) {
        $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_10_12_000001_create_html_cache_request_observations_table.php';
        $migration->up();
    }
});

function publicObservationRequest(): Request
{
    $domain = SiteDomain::factory()->create();
    $page = Page::factory()->site($domain->site)->withTranslations($domain->language)->create();
    $pageUrl = PageUrl::factory()->page($page)->site($domain->site)->language($domain->language)
        ->create(['url' => '/observed-public-page']);
    $pageUrl->setRelation('siteDomain', $domain);

    $request = Request::create($pageUrl->fullUrl());
    $request->setUserResolver(static fn (): null => null);

    return $request;
}

/**
 * Recording is deferred until after the response, so run the deferred callbacks
 * the way the HTTP kernel does when it terminates.
 */
function handleAndFlushObservations(Request $request, Closure $next): SymfonyResponse
{
    $response = resolve(HtmlCacheMiddleware::class)->handle($request, $next);
    resolve(DeferredCallbackCollection::class)->invoke();

    return $response;
}

it('records an actual public render even when HTML caching is disabled', function (): void {
    $request = publicObservationRequest();
    $response = handleAndFlushObservations($request, static fn (): Response => response(
        '<!doctype html><html><body>Public observation</body></html>',
        headers: ['Content-Type' => 'text/html'],
    ));
    $row = DB::table('html_cache_request_observations')->first();

    expect($response->getStatusCode())->toBe(200)
        ->and($row)->not->toBeNull()
        ->and($row?->weighted_hits)->toBe(0)
        ->and($row?->weighted_misses)->toBe(1)
        ->and((float) ($row->last_render_milliseconds ?? 0))->toBeGreaterThan(0);
});

it('records observations only after the response has been returned', function (): void {
    $request = publicObservationRequest();

    $response = resolve(HtmlCacheMiddleware::class)->handle($request, static fn (): Response => response(
        '<!doctype html><html><body>Public observation</body></html>',
        headers: ['Content-Type' => 'text/html'],
    ));
    $beforeTermination = DB::table('html_cache_request_observations')->count();
    resolve(DeferredCallbackCollection::class)->invoke();

    expect($response->getStatusCode())->toBe(200)
        ->and($beforeTermination)->toBe(0)
        ->and(DB::table('html_cache_request_observations')->count())->toBe(1);
});

it('excludes query, head, synthetic, authenticated and unknown URLs from observations', function (string $excluded): void {
    $request = publicObservationRequest();
    $sessionCookie = config('session.cookie');
    if (! is_string($sessionCookie)) {
        throw new RuntimeException('The fixture requires a configured session cookie.');
    }

    match ($excluded) {
        'query' => $request->query->set('preview', 'secret'),
        'head' => $request->setMethod('HEAD'),
        'synthetic' => $request->attributes->set(HtmlCacheMiddleware::SYNTHETIC_RENDER_ATTRIBUTE, true),
        'authenticated' => $request->headers->set('Authorization', 'Bearer private'),
        'user' => $request->setUserResolver(static fn (): User => User::factory()->make()),
        'session-cookie' => $request->cookies->set($sessionCookie, 'private'),
        'access-gate-cookie' => $request->cookies->set('capell_access_gate_browser_token', 'private'),
        'protected' => $request->attributes->set('access_gate.protected', true),
        'unknown' => $request->server->set('REQUEST_URI', '/unknown-public-page'),
        default => throw new InvalidArgumentException('Unknown observation exclusion fixture.'),
    };
    handleAndFlushObservations($request, static fn (): Response => response(
        '<!doctype html><html><body>Public observation</body></html>',
        headers: ['Content-Type' => 'text/html'],
    ));

    expect(DB::table('html_cache_request_observations')->count())->toBe(0);
})->with(['query', 'head', 'synthetic', 'authenticated', 'user', 'session-cookie', 'access-gate-cookie', 'protected', 'unknown']);

it('samples production hits and renders with the same weight', function (): void {
    $request = publicObservationRequest();
    app()->instance('request', $request);
    app()->detectEnvironment(static fn (): string => 'production');
    config(['capell-html-cache.observation_recording.sample_rate' => 10]);
    $response = response('<html><body>Public page</body></html>', headers: ['Content-Type' => 'text/html']);
    Lottery::alwaysLose(static fn () => RecordHtmlCacheRequestObservationAction::run($request, $response, 80.0));
    expect(DB::table(HtmlCacheRequestObservationStore::TABLE)->count())->toBe(0);
    Lottery::alwaysWin(static function () use ($request, $response): void {
        RecordHtmlCacheRequestObservationAction::run($request, $response, 80.0);
        RecordHtmlCacheRequestObservationAction::run($request, $response, null);
    });
    $row = DB::table(HtmlCacheRequestObservationStore::TABLE)->first();

    expect($row?->weighted_hits)->toBe(10)
        ->and($row?->weighted_misses)->toBe(10)
        ->and((float) $row?->last_render_milliseconds)->toBe(80.0);
});

it('keeps a public response intact when observation storage fails', function (): void {
    $request = publicObservationRequest();
    Schema::table(HtmlCacheRequestObservationStore::TABLE, static fn (Blueprint $table) => $table->dropColumn('weighted_misses'));
    try {
        $response = handleAndFlushObservations($request, static fn (): Response => response(
            '<html><body>Public page remains available</body></html>',
            headers: ['Content-Type' => 'text/html'],
        ));
        expect($response->getStatusCode())->toBe(200)
            ->and($response->getContent())->toBe('<html><body>Public page remains available</body></html>');
    } finally {
        Schema::table(HtmlCacheRequestObservationStore::TABLE, static fn (Blueprint $table) => $table->unsignedBigInteger('weighted_misses')->default(0));
    }
});

it('keeps observation reads out of runtime eligibility decisions', function (): void {
    $request = publicObservationRequest();
    $response = response('<html><body>Public page</body></html>', headers: ['Content-Type' => 'text/html']);
    RecordHtmlCacheRequestObservationAction::run($request, $response, 80.0);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $report = BuildHtmlCacheEligibilityReportAction::run($request, $response);
    $queries = json_encode(DB::getQueryLog(), JSON_THROW_ON_ERROR);
    DB::disableQueryLog();

    expect($report->requestMetrics)->toBeNull()
        ->and($queries)->not->toContain(HtmlCacheRequestObservationStore::TABLE);
});
