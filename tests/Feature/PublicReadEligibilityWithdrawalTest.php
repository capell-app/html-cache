<?php

declare(strict_types=1);

use Capell\Core\Actions\PublicRead\RecordPublicReadEligibilityChangeAction;
use Capell\Core\Contracts\PublicRead\EvaluatesPublicPageReadCandidatesIndividually;
use Capell\Core\Contracts\PublicRead\PublicPageReadEligibility;
use Capell\Core\Contracts\PublicRead\SchedulesPublicReadTransitions;
use Capell\Core\Data\PublicRead\PublicPageReadCandidateData;
use Capell\Core\Events\PublicReadEligibilityChanged;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\PublicRead\PublicArtefactPermit;
use Capell\Core\Support\PublicRead\PublicPageReadEligibilityRegistry;
use Capell\Core\Support\PublicRead\PublicReadEligibilityState;
use Capell\Frontend\Actions\AssertPublicHtmlContainsNoAuthoringSurfaceAction;
use Capell\Frontend\Support\State\FrontendState;
use Capell\Frontend\Support\Static\StaticPageArtifactStore;
use Capell\HtmlCache\Contracts\CachePurger;
use Capell\HtmlCache\Data\EdgeCachePurgeData;
use Capell\HtmlCache\Enums\HtmlCacheEligibilityReason;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Capell\HtmlCache\Support\Cache\HtmlCachePublicationGuard;
use Capell\HtmlCache\Support\Cache\PageCache;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Carbon\CarbonImmutable;
use Illuminate\Cache\ArrayLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

uses(HtmlCacheTestCase::class);

const PUBLIC_READ_DIRECTORY_HTML = '<html><body>Directory</body></html>';

/** A request whose page has passed the public HTML safety inspection. */
function htmlCacheInspectedRequest(string $url, string $html): Request
{
    $request = Request::create($url);
    $request->attributes->set(AssertPublicHtmlContainsNoAuthoringSurfaceAction::SAFE_INSPECTION_PASSED_ATTRIBUTE, true);
    $request->attributes->set(AssertPublicHtmlContainsNoAuthoringSurfaceAction::SAFE_INSPECTION_HASH_ATTRIBUTE, hash('xxh128', $html));

    return $request;
}

it('withdraws every cached page, fences in-flight renders and purges the edge when public-read eligibility changes', function (string $source): void {
    Storage::fake('page_cache');
    config()->set('capell-html-cache.invalidation.mode', 'scheduled');
    config()->set('queue.default', 'sync');

    $purges = new ArrayObject;
    app()->instance(CachePurger::class, new readonly class($purges) implements CachePurger
    {
        /** @param ArrayObject<int, EdgeCachePurgeData> $purges */
        public function __construct(private ArrayObject $purges) {}

        public function purge(EdgeCachePurgeData $purge): bool
        {
            $this->purges->append($purge);

            return true;
        }
    });

    // A page outside any default area still lists or links to the page whose rules changed.
    $domain = Model::withoutEvents(fn (): SiteDomain => SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'listing.example.test', 'path' => null]));
    $path = resolve(HtmlCachePathResolver::class)->pathForUrl('/staff-directory', $domain);
    Storage::disk('page_cache')->put($path, 'Links to a staff-only page');
    $guard = resolve(HtmlCachePublicationGuard::class);
    $token = $guard->capture(Request::create('https://listing.example.test/staff-directory'));

    RecordPublicReadEligibilityChangeAction::run($source);

    expect(Storage::disk('page_cache')->exists($path))->toBeFalse()
        ->and($guard->publish($token, static fn (): bool => true))->toBeFalse()
        ->and($purges->count())->toBeGreaterThan(0)
        ->and(collect($purges->getArrayCopy())->first()?->purgeAll)->toBeTrue();
})->with(['access-gate.area', 'access-gate.schedule', 'access-gate.site-policy']);

/** A contributor that allows every page and names one scheduled transition. */
function publicReadScheduledContributor(CarbonImmutable $transition): void
{
    resolve(PublicPageReadEligibilityRegistry::class)->register(new readonly class($transition) implements PublicPageReadEligibility, SchedulesPublicReadTransitions
    {
        use EvaluatesPublicPageReadCandidatesIndividually;

        public function __construct(private CarbonImmutable $transition) {}

        public function key(): string
        {
            return 'test-scheduled-area';
        }

        public function isPubliclyReadable(PublicPageReadCandidateData $candidate): bool
        {
            return true;
        }

        public function nextPublicReadTransitionAfter(CarbonImmutable $now, ?int $siteId = null): ?CarbonImmutable
        {
            return $now->lessThan($this->transition) ? $this->transition : null;
        }
    });
}

/** Renders and caches one page the way the middleware does, returning whether a disk copy exists. */
function publicReadCacheThroughWriter(string $url): bool
{
    $request = htmlCacheInspectedRequest($url, PUBLIC_READ_DIRECTORY_HTML);
    app()->instance('request', $request);
    resolve(HtmlCachePublicationGuard::class)->capture($request);
    $response = new Response(PUBLIC_READ_DIRECTORY_HTML, Symfony\Component\HttpFoundation\Response::HTTP_OK, ['Content-Type' => 'text/html']);

    if (resolve(PageCache::class)->shouldCachePage($request, $response)) {
        resolve(PageCache::class)->cache($request, $response);
    }

    $domain = SiteDomain::query()->where('domain', 'listing.example.test')->firstOrFail();

    return Storage::disk('page_cache')->exists(resolve(HtmlCachePathResolver::class)->pathForUrl((string) parse_url($url, PHP_URL_PATH), $domain));
}

it('writes no directly served copy of a page whose rules change at a known time, even on a synchronous queue', function (): void {
    Storage::fake('page_cache');
    config()->set('capell-html-cache.enabled', true);
    config()->set('capell-html-cache.write_enabled', true);
    config()->set('queue.default', 'sync');
    Model::withoutEvents(fn (): SiteDomain => SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'listing.example.test', 'path' => null]));
    $this->travelTo(CarbonImmutable::parse('2026-11-03 10:00:00', 'UTC'));
    $transition = CarbonImmutable::parse('2026-11-03 10:10:00', 'UTC');
    publicReadScheduledContributor($transition);
    Event::fake([PublicReadEligibilityChanged::class]);

    $request = htmlCacheInspectedRequest('https://listing.example.test/staff-directory', PUBLIC_READ_DIRECTORY_HTML);

    expect(resolve(PageCache::class)->rejectionReason($request, new Response(PUBLIC_READ_DIRECTORY_HTML, Symfony\Component\HttpFoundation\Response::HTTP_OK, ['Content-Type' => 'text/html'])))
        ->toBe(HtmlCacheEligibilityReason::PublicReadTimeBounded)
        ->and(publicReadCacheThroughWriter('https://listing.example.test/staff-directory'))->toBeFalse();
    // The synchronous queue ran no early cleanup.
    Event::assertNotDispatched(PublicReadEligibilityChanged::class);

    // Past the transition, Laravel decides afresh: with nothing further scheduled, a copy may be written.
    $this->travelTo($transition->addSecond());
    app()->forgetScopedInstances();

    expect(resolve(PageCache::class)->rejectionReason($request, new Response(PUBLIC_READ_DIRECTORY_HTML, Symfony\Component\HttpFoundation\Response::HTTP_OK, ['Content-Type' => 'text/html'])))->toBeNull()
        ->and(publicReadCacheThroughWriter('https://listing.example.test/staff-directory'))->toBeTrue();
});

it('leaves no disk copy and holds the cleanup marker only for the retry interval when the cleanup dispatch fails', function (): void {
    Storage::fake('page_cache');
    config()->set('capell-html-cache.enabled', true);
    config()->set('capell-html-cache.write_enabled', true);
    config()->set('queue.default', 'database');
    Model::withoutEvents(fn (): SiteDomain => SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'listing.example.test', 'path' => null]));
    $transition = CarbonImmutable::now()->addMinutes(10);
    publicReadScheduledContributor($transition);
    Bus::shouldReceive('dispatch')->andThrow(new RuntimeException('The queue is unavailable.'));
    app()->instance(Dispatcher::class, Bus::getFacadeRoot());

    expect(publicReadCacheThroughWriter('https://listing.example.test/staff-directory'))->toBeFalse()
        // An unavailable queue is tried at most once per interval, not on every request.
        ->and(Cache::has(PublicReadEligibilityState::EXPIRY_SCHEDULED_PREFIX . $transition->getTimestamp()))->toBeTrue();

    $this->travel(PublicReadEligibilityState::EXPIRY_DISPATCH_RETRY_SECONDS + 1)->seconds();

    expect(Cache::has(PublicReadEligibilityState::EXPIRY_SCHEDULED_PREFIX . $transition->getTimestamp()))->toBeFalse();
});

it('discards a copy built before a scheduled transition and written after it', function (): void {
    Storage::fake('page_cache');
    config()->set('capell-html-cache.enabled', true);
    config()->set('capell-html-cache.write_enabled', true);
    Model::withoutEvents(fn (): SiteDomain => SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'listing.example.test', 'path' => null]));
    $transition = CarbonImmutable::now()->addMinutes(10);
    publicReadScheduledContributor($transition);
    Queue::fake();

    // Built before the transition (the token carries the stamp it was built under)...
    $request = htmlCacheInspectedRequest('https://listing.example.test/staff-directory', PUBLIC_READ_DIRECTORY_HTML);
    app()->instance('request', $request);
    $token = resolve(HtmlCachePublicationGuard::class)->capture($request);

    // ...written after it, on a host whose clock or worker ran late.
    $this->travelTo($transition->addMinute());
    app()->forgetScopedInstances();

    expect(resolve(HtmlCachePublicationGuard::class)->publish($token, static fn (): bool => true))->toBeFalse()
        ->and(resolve(PageCache::class)->cache($request, new Response(PUBLIC_READ_DIRECTORY_HTML, Symfony\Component\HttpFoundation\Response::HTTP_OK, ['Content-Type' => 'text/html'])))->toBeFalse();
});

it('writes no cached copy while the public-read rules cannot be recorded', function (): void {
    Storage::fake('page_cache');
    config()->set('capell-html-cache.enabled', true);
    resolve(PublicPageReadEligibilityRegistry::class)->register(new class implements PublicPageReadEligibility
    {
        use EvaluatesPublicPageReadCandidatesIndividually;

        public function key(): string
        {
            return 'test-area';
        }

        public function isPubliclyReadable(PublicPageReadCandidateData $candidate): bool
        {
            return true;
        }
    });
    Schema::rename('public_read_eligibility_state', 'public_read_eligibility_state_unavailable');
    app()->forgetScopedInstances();

    try {
        $reason = resolve(PageCache::class)->rejectionReason(htmlCacheInspectedRequest('https://listing.example.test/staff-directory', PUBLIC_READ_DIRECTORY_HTML), new Response(PUBLIC_READ_DIRECTORY_HTML, Symfony\Component\HttpFoundation\Response::HTTP_OK, ['Content-Type' => 'text/html']));
    } finally {
        Schema::rename('public_read_eligibility_state_unavailable', 'public_read_eligibility_state');
    }

    expect($reason)->toBe(HtmlCacheEligibilityReason::CacheWriteDisabled);
});

it('writes every disk copy through Core publication, and only with its live permit', function (): void {
    Storage::fake('page_cache');
    config()->set('capell-html-cache.enabled', true);
    config()->set('capell-html-cache.write_enabled', true);
    Model::withoutEvents(fn (): SiteDomain => SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'listing.example.test', 'path' => null]));

    $kept = new ArrayObject;
    $request = htmlCacheInspectedRequest('https://listing.example.test/kept', PUBLIC_READ_DIRECTORY_HTML);
    $token = resolve(HtmlCachePublicationGuard::class)->capture($request);

    expect(resolve(HtmlCachePublicationGuard::class)->publish($token, static function (PublicArtefactPermit $permit) use ($kept): bool {
        $kept->append($permit);

        return true;
    }))->toBeTrue()
        ->and($kept->count())->toBe(1)
        ->and(array_all($kept->getArrayCopy(), static fn (PublicArtefactPermit $permit): bool => ! $permit->isActive()))->toBeTrue()
        ->and(publicReadCacheThroughWriter('https://listing.example.test/staff-directory'))->toBeTrue();

    // A permit for the HTML cache, even while active, cannot write another store.
    $crossed = null;
    $other = resolve(HtmlCachePublicationGuard::class)->capture(htmlCacheInspectedRequest('https://listing.example.test/crossed', PUBLIC_READ_DIRECTORY_HTML));
    resolve(HtmlCachePublicationGuard::class)->publish($other, static function (PublicArtefactPermit $permit) use (&$crossed): bool {
        try {
            resolve(StaticPageArtifactStore::class)->putHtml($permit, 'elsewhere/index.html', 'Not this store');
        } catch (RuntimeException $runtimeException) {
            $crossed = $runtimeException->getMessage();
        }

        return true;
    });

    expect($crossed)->toContain('under the lock of the store being written');
});

it('refuses a disk copy whose rules changed after its render began', function (): void {
    Storage::fake('page_cache');
    config()->set('capell-html-cache.enabled', true);
    config()->set('capell-html-cache.write_enabled', true);
    Model::withoutEvents(fn (): SiteDomain => SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'listing.example.test', 'path' => null]));
    publicReadScheduledContributor(CarbonImmutable::now()->subDay());
    $request = htmlCacheInspectedRequest('https://listing.example.test/staff-directory', PUBLIC_READ_DIRECTORY_HTML);
    app()->instance('request', $request);
    $token = resolve(HtmlCachePublicationGuard::class)->capture($request);
    $wrote = false;

    // The change and its withdrawal complete before the writer takes the guard lock.
    RecordPublicReadEligibilityChangeAction::run('test');

    expect(resolve(HtmlCachePublicationGuard::class)->publish($token, static function () use (&$wrote): bool {
        $wrote = true;

        return true;
    }))->toBeFalse()
        ->and($wrote)->toBeFalse();
});

it('publishes a disk copy while no leased cache lock can be taken, holding the guard lock a withdrawal needs', function (): void {
    Storage::fake('page_cache');
    config()->set('capell-html-cache.enabled', true);
    config()->set('capell-html-cache.write_enabled', true);
    Model::withoutEvents(fn (): SiteDomain => SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'listing.example.test', 'path' => null]));
    // No cache lock is ever available: a disk copy must not depend on a lease.
    Cache::extend('no-locks', fn (): Repository => Cache::repository(new class extends ArrayStore
    {
        public function lock($name, $seconds = 0, $owner = null): Lock
        {
            return new class($this, $name, $seconds, $owner) extends ArrayLock
            {
                public function acquire(): bool
                {
                    return false;
                }
            };
        }
    }));
    config(['cache.stores.no-locks' => ['driver' => 'no-locks'], 'cache.default' => 'no-locks']);
    Cache::forgetDriver('no-locks');
    $guard = resolve(HtmlCachePublicationGuard::class);
    $request = htmlCacheInspectedRequest('https://listing.example.test/fenced', PUBLIC_READ_DIRECTORY_HTML);
    $token = $guard->capture($request);
    $withdrawalCouldStart = null;

    $published = $guard->publish($token, static function (PublicArtefactPermit $permit) use ($guard, &$withdrawalCouldStart): bool {
        $probe = fopen($guard->lockPath(), 'c+b');
        throw_if($probe === false, RuntimeException::class, 'The publication lock cannot be opened.');
        $withdrawalCouldStart = flock($probe, LOCK_EX | LOCK_NB);
        fclose($probe);

        return true;
    });

    expect($published)->toBeTrue()
        // While the copy is written, a withdrawal of the store would have to wait.
        ->and($withdrawalCouldStart)->toBeFalse();
});

it('keeps writing disk copies for a site while only another site has a scheduled change', function (): void {
    Storage::fake('page_cache');
    config()->set('capell-html-cache.enabled', true);
    config()->set('capell-html-cache.write_enabled', true);
    $listing = Model::withoutEvents(fn (): SiteDomain => SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'listing.example.test', 'path' => null]));
    $embargoed = Model::withoutEvents(fn (): SiteDomain => SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'embargoed.example.test', 'path' => null]));
    resolve(PublicPageReadEligibilityRegistry::class)->register(new readonly class($embargoed->site_id) implements PublicPageReadEligibility, SchedulesPublicReadTransitions
    {
        use EvaluatesPublicPageReadCandidatesIndividually;

        public function __construct(private int $embargoedSite) {}

        public function key(): string
        {
            return 'test-site-embargo';
        }

        public function isPubliclyReadable(PublicPageReadCandidateData $candidate): bool
        {
            return true;
        }

        public function nextPublicReadTransitionAfter(CarbonImmutable $now, ?int $siteId = null): ?CarbonImmutable
        {
            return $siteId === null || $siteId === $this->embargoedSite ? $now->addHour() : null;
        }
    });

    resolve(FrontendState::class)->withSite($listing->site);

    expect(publicReadCacheThroughWriter('https://listing.example.test/staff-directory'))->toBeTrue();

    resolve(FrontendState::class)->withSite($embargoed->site);
    $request = htmlCacheInspectedRequest('https://embargoed.example.test/staff-directory', PUBLIC_READ_DIRECTORY_HTML);

    expect(resolve(PageCache::class)->rejectionReason($request, new Response(PUBLIC_READ_DIRECTORY_HTML, Symfony\Component\HttpFoundation\Response::HTTP_OK, ['Content-Type' => 'text/html'])))
        ->toBe(HtmlCacheEligibilityReason::PublicReadTimeBounded);
});

it('resolves the request\'s site before the build stamp is captured, so another site\'s schedule does not stop its disk copy', function (): void {
    Storage::fake('page_cache');
    config()->set('capell-html-cache.enabled', true);
    config()->set('capell-html-cache.write_enabled', true);
    $listing = Model::withoutEvents(fn (): SiteDomain => SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'listing.example.test', 'path' => null]));
    $embargoed = Model::withoutEvents(fn (): SiteDomain => SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'embargoed.example.test', 'path' => null]));
    resolve(PublicPageReadEligibilityRegistry::class)->register(new readonly class($embargoed->site_id) implements PublicPageReadEligibility, SchedulesPublicReadTransitions
    {
        use EvaluatesPublicPageReadCandidatesIndividually;

        public function __construct(private int $embargoedSite) {}

        public function key(): string
        {
            return 'test-site-embargo';
        }

        public function isPubliclyReadable(PublicPageReadCandidateData $candidate): bool
        {
            return true;
        }

        public function nextPublicReadTransitionAfter(CarbonImmutable $now, ?int $siteId = null): ?CarbonImmutable
        {
            return $siteId === null || $siteId === $this->embargoedSite ? $now->addHour() : null;
        }
    });

    // The middleware runs before the frontend resolves the site, as in production.
    resolve(FrontendState::class)->reset();
    $request = Request::create('https://listing.example.test/staff-directory');
    app()->instance('request', $request);

    resolve(HtmlCacheMiddleware::class)->handle($request, function () use ($listing): Response {
        resolve(FrontendState::class)->withSite($listing->site);

        return new Response(PUBLIC_READ_DIRECTORY_HTML, Symfony\Component\HttpFoundation\Response::HTTP_OK, ['Content-Type' => 'text/html', 'Cache-Control' => 'public']);
    });

    expect(Storage::disk('page_cache')->exists(resolve(HtmlCachePathResolver::class)->pathForUrl('/staff-directory', $listing)))->toBeTrue();
});
