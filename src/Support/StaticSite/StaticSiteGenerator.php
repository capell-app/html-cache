<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\StaticSite;

use Capell\Core\Actions\SiteDomains\ResolveSiteDomainUrlAction;
use Capell\Core\Actions\VisitUrlAction;
use Capell\Core\Exceptions\UrlVisitFailedException;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Capell\HtmlCache\Support\Cache\HtmlCacheStore;
use Closure;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class StaticSiteGenerator
{
    public function __construct(
        public Site $site,
        private readonly bool $refresh = false,
    ) {}

    public function process(
        ?Closure $start = null,
        ?Closure $prepare = null,
        ?Closure $checkpoint = null,
        ?Closure $end = null,
    ): void {
        $this->site->loadMissing('siteDomains');
        throw_if($this->site->siteDomains->isEmpty(), RuntimeException::class, 'No site domains found for static HTML generation.');

        foreach ($this->site->siteDomains as $siteDomain) {
            $this->processSiteDomain($siteDomain, $start, $prepare, $checkpoint, $end);
        }
    }

    private function processSiteDomain(
        SiteDomain $siteDomain,
        ?Closure $start,
        ?Closure $prepare,
        ?Closure $checkpoint,
        ?Closure $end,
    ): void {
        $start?->__invoke($this->site, $siteDomain);

        $totalUrls = $this->loadUrlCount($siteDomain);
        $totalUrls += $this->processExtensionHandlers($siteDomain, $checkpoint);

        $prepare?->__invoke($totalUrls, $siteDomain);
        $this->visitAllUrls($siteDomain, $checkpoint);
        $end?->__invoke($this->site, $siteDomain);
    }

    private function loadUrlCount(SiteDomain $siteDomain): int
    {
        $siteDomain->loadCount([
            'pageUrls' => fn (Builder $query): Builder => $query->whereHas(
                'pageable',
                fn (BuilderContract $query): BuilderContract => $query->whereHas(
                    'blueprint',
                    fn (BuilderContract $query): BuilderContract => $query->enabled()->accessible(),
                ),
            ),
        ]);

        return (int) $siteDomain->page_urls_count;
    }

    private function processExtensionHandlers(SiteDomain $siteDomain, ?Closure $checkpoint): int
    {
        $extraTotals = 0;

        foreach (resolve(StaticSiteExtensionRegistry::class)->all() as $handler) {
            $handler($this->site, $siteDomain, function (string $url) use ($siteDomain, $checkpoint, &$extraTotals): void {
                $url = $this->resolveExtensionUrl($url, $siteDomain);
                $this->visitUrl($url);
                $extraTotals++;
                $checkpoint?->__invoke($url);
            });
        }

        return $extraTotals;
    }

    private function resolveExtensionUrl(string $url, SiteDomain $siteDomain): string
    {
        $components = parse_url($url);

        if ($components === false || $url === '' || preg_match('/[\\x00-\\x20\\x7f\\\\\\\\]/', $url) === 1) {
            throw UrlVisitFailedException::forUrl($url, __('capell::message.visit_requires_http'));
        }

        if (! isset($components['scheme']) && ! isset($components['host']) && isset($components['path'])) {
            return ResolveSiteDomainUrlAction::run($siteDomain, $url);
        }

        if (in_array($components['scheme'] ?? null, ['http', 'https'], true)
            && is_string($components['host'] ?? null)
            && $components['host'] !== '') {
            return $url;
        }

        throw UrlVisitFailedException::forUrl($url, __('capell::message.visit_requires_http'));
    }

    private function visitAllUrls(SiteDomain $siteDomain, ?Closure $checkpoint): void
    {
        PageUrl::query()
            ->whereHas(
                'pageable',
                fn (BuilderContract $query): BuilderContract => $query
                    ->whereHas(
                        'blueprint',
                        fn (BuilderContract $query): BuilderContract => $query->enabled()->accessible(),
                    ),
            )
            ->where('site_id', $siteDomain->site_id)
            ->where('language_id', $siteDomain->language_id)
            ->orderBy('id')
            ->lazy()
            ->each(function (PageUrl $pageUrl) use ($checkpoint, $siteDomain): void {
                $pageUrl->setRelation('siteDomain', $siteDomain);
                $url = $pageUrl->full_url;

                $this->refreshPageCache($pageUrl, $siteDomain);
                $this->visitUrl($url);
                $checkpoint?->__invoke($url);
            });
    }

    private function visitUrl(string $url): void
    {
        if (config('capell-html-cache.static_generation.internal_requests', false) === true) {
            $this->visitUrlInternally($url);

            return;
        }

        // The visitor can reject a destination without sending a request.
        $status = resolve(StaticSiteRequestObserver::class)->statusFor($url, function () use ($url): void {
            VisitUrlAction::run($url);
        });

        if ($status !== 200) {
            throw new RuntimeException($status === null
                ? 'Static generation did not receive a response for a required URL.'
                : sprintf('Static generation request returned HTTP %d.', $status));
        }
    }

    private function refreshPageCache(PageUrl $pageUrl, SiteDomain $siteDomain): void
    {
        if (! $this->refresh) {
            return;
        }

        $pathResolver = resolve(HtmlCachePathResolver::class);
        $store = resolve(HtmlCacheStore::class);

        $store->deletePage($pathResolver->pathForUrl($pageUrl->url, $siteDomain));
        $store->deletePage($pathResolver->pathForUrl($pageUrl->url, $siteDomain, error: true));
    }

    private function visitUrlInternally(string $url): void
    {
        $components = parse_url($url);

        $host = is_array($components) ? ($components['host'] ?? null) : null;
        $path = is_array($components) ? ($components['path'] ?? '/') : null;
        $query = is_array($components) ? ($components['query'] ?? null) : null;
        $scheme = is_array($components) ? ($components['scheme'] ?? null) : null;

        if (! in_array($scheme, ['http', 'https'], true) || ! is_string($host) || $host === '' || ! is_string($path)) {
            Log::warning('StaticSiteGenerator: rejected invalid internal url', ['url' => $url]);

            throw UrlVisitFailedException::forUrl($url, __('capell::message.visit_requires_http'));
        }

        $uri = $query === null ? $path : $path . '?' . $query;
        $port = $components['port'] ?? ($scheme === 'http' ? 80 : 443);
        $hostHeader = $port === 80 || $port === 443 ? $host : sprintf('%s:%d', $host, $port);

        $request = Request::create($uri, \Symfony\Component\HttpFoundation\Request::METHOD_GET, server: [
            'HTTP_HOST' => $hostHeader,
            'SERVER_NAME' => $host,
            'SERVER_PORT' => $port,
            'HTTPS' => $scheme === 'https' ? 'on' : 'off',
        ]);
        $request->attributes->set(HtmlCacheMiddleware::SYNTHETIC_RENDER_ATTRIBUTE, true);

        $kernel = resolve(HttpKernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        if (! $response->isSuccessful()) {
            throw new RuntimeException(sprintf(
                'Static generation request [%s] returned HTTP %d.',
                $url,
                $response->getStatusCode(),
            ));
        }
    }
}
