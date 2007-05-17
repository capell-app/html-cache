<?php

declare(strict_types=1);

use Capell\Blog\Data\ArchiveMonthData;
use Capell\Blog\Support\PageArchiveService;
use Capell\Blog\Support\StaticSite\BlogStaticSiteExtension;
use Capell\Core\Actions\ResolveFirstPageByTypeAction;
use Capell\Core\Exceptions\UrlVisitFailedException;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Support\StaticSite\StaticSiteExtensionRegistry;
use Capell\HtmlCache\Support\StaticSite\StaticSiteGenerator;
use Capell\HtmlCache\Support\StaticSite\StaticSiteRequestObserver;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

uses(HtmlCacheTestCase::class);

function staticExtensionUrlDomain(): SiteDomain
{
    return SiteDomain::factory()->create([
        'domain' => '8.8.8.8',
        'scheme' => 'https',
        'port' => 8443,
        'path' => '/section',
        'status' => true,
    ]);
}

it('visits blog archive extension paths before counting them as generated', function (bool $internal): void {
    $domain = staticExtensionUrlDomain();
    $site = $domain->site;
    $site->setRelation('siteDomains', collect([$domain]));
    $archive = new Page;
    $archive->setRelation('pageUrl', new PageUrl(['url' => '/blog/archive/*']));
    app()->instance(ResolveFirstPageByTypeAction::class, new readonly class($archive)
    {
        public function __construct(private Page $archive) {}

        public function handle(string $key, Site $site, ?Language $language = null, ?callable $modifyQueryUsing = null): ?Page
        {
            return $key === 'archive' ? $this->archive : null;
        }
    });
    $archives = Mockery::mock(PageArchiveService::class);
    $archives->shouldReceive('getArchivedCountsByMonth')->once()->andReturn(collect([
        new ArchiveMonthData(year: 2026, month: 7, total: 2),
    ]));
    app()->instance(PageArchiveService::class, $archives);
    $registry = new StaticSiteExtensionRegistry;
    $registry->register('blog', new BlogStaticSiteExtension);
    app()->instance(StaticSiteExtensionRegistry::class, $registry);
    $observer = new StaticSiteRequestObserver;
    app()->instance(StaticSiteRequestObserver::class, $observer);
    Event::listen(ResponseReceived::class, [$observer, 'record']);
    config(['capell-html-cache.static_generation.internal_requests' => $internal]);
    Http::fake(['*' => Http::response('Archive', 200)]);
    $expectedUrl = 'https://8.8.8.8:8443/section/blog/archive/2026/07';

    $kernel = Mockery::mock(HttpKernel::class);
    if ($internal) {
        $kernel->shouldReceive('handle')->once()->with(Mockery::on(
            fn (Request $request): bool => $request->getUri() === $expectedUrl
            && $request->attributes->get(HtmlCacheMiddleware::SYNTHETIC_RENDER_ATTRIBUTE) === true,
        ))->andReturn(new Response('Archive', 200));
        $kernel->shouldReceive('terminate')->once();
    } else {
        $kernel->shouldNotReceive('handle', 'terminate');
    }
    app()->instance(HttpKernel::class, $kernel);
    $checkpoints = [];
    $totals = [];
    $ended = false;

    (new StaticSiteGenerator($site))->process(
        prepare: function (int $total) use (&$totals): void {
            $totals[] = $total;
        },
        checkpoint: function (string $url) use (&$checkpoints): void {
            $checkpoints[] = $url;
        },
        end: function () use (&$ended): void {
            $ended = true;
        },
    );

    expect($checkpoints)->toBe([$expectedUrl])
        ->and($totals)->toBe([1])
        ->and($ended)->toBeTrue();
    if ($internal) {
        Http::assertNothingSent();
    } else {
        Http::assertSentCount(1);
        Http::assertSent(fn (Illuminate\Http\Client\Request $request): bool => $request->url() === $expectedUrl);
    }
})->with(['internal' => true, 'external' => false]);

it('rejects invalid extension destinations without counting or checkpointing them', function (bool $internal, string $url): void {
    $domain = staticExtensionUrlDomain();
    $site = $domain->site;
    $site->setRelation('siteDomains', collect([$domain]));
    $registry = new StaticSiteExtensionRegistry;
    $registry->register('invalid', function (Site $site, SiteDomain $domain, Closure $visit) use ($url): void {
        $visit($url);
    });
    app()->instance(StaticSiteExtensionRegistry::class, $registry);
    config(['capell-html-cache.static_generation.internal_requests' => $internal]);
    Http::fake();
    $kernel = Mockery::mock(HttpKernel::class);
    $kernel->shouldNotReceive('handle', 'terminate');
    app()->instance(HttpKernel::class, $kernel);
    $checkpoints = [];
    $ended = false;

    $generate = function () use ($site, &$checkpoints, &$ended): void {
        (new StaticSiteGenerator($site))->process(
            checkpoint: function (string $visited) use (&$checkpoints): void {
                $checkpoints[] = $visited;
            },
            end: function () use (&$ended): void {
                $ended = true;
            },
        );
    };
    expect($generate)->toThrow(UrlVisitFailedException::class);

    expect($checkpoints)->toBe([])->and($ended)->toBeFalse();
    Http::assertNothingSent();
})->with(['internal' => true, 'external' => false])->with([
    'empty' => '',
    'file scheme' => 'file:///private/export',
    'missing host' => 'https:///archive',
    'network path' => '//outside.test/archive',
]);
