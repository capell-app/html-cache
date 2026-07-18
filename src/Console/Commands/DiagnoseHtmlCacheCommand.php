<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Console\Commands;

use Capell\Core\Models\PageUrl;
use Capell\HtmlCache\Actions\BuildHtmlCacheEligibilityReportAction;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class DiagnoseHtmlCacheCommand extends Command
{
    protected $description = 'Diagnose why a public URL is or is not eligible for the Capell HTML cache.';

    protected $signature = 'capell:html-cache:diagnose
        {url? : Absolute URL or path to inspect}
        {--site= : Optional site id used to resolve a PageUrl row for diagnostics}
        {--render : Render the URL through the current Laravel kernel and inspect the real response}
        {--json : Output the report as JSON}';

    public function handle(): int
    {
        $url = $this->url();
        $request = Request::create($url, \Symfony\Component\HttpFoundation\Request::METHOD_GET);
        $pageUrl = $this->pageUrl($request);
        $response = $this->option('render') === true ? $this->renderResponse($request) : null;
        $report = BuildHtmlCacheEligibilityReportAction::run($request, response: $response, pageUrl: $pageUrl);

        if ($this->option('json') === true) {
            $this->line(json_encode([
                ...$report->toArray(),
                'response' => $response instanceof Response ? $this->responseMetadata($response) : null,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        $rows = [
            ['URL', $report->url],
            ['Eligible', $report->eligible ? 'yes' : 'no'],
            ['Reasons', $report->reasonCodes() === [] ? 'none' : implode(', ', $report->reasonCodes())],
            ['Blocking packages', $report->blockingPackages === [] ? 'none' : implode(', ', $report->blockingPackages)],
            ['Cache tags', $report->cacheTags === [] ? 'none' : implode(', ', $report->cacheTags)],
            ['Cache state', $report->cacheState],
            ['Stale', $report->stale ? 'yes' : 'no'],
            ['Last cached at', $report->lastCachedAt ?? 'never'],
        ];

        if ($response instanceof Response) {
            $metadata = $this->responseMetadata($response);
            $rows[] = ['Response status', (string) $metadata['status']];
            $rows[] = ['Cache-Control', $metadata['cache_control']];
            $rows[] = ['Vary', $metadata['vary']];
            $rows[] = ['Set-Cookie count', (string) $metadata['set_cookie_count']];
            $rows[] = ['Surrogate-Key', $metadata['surrogate_key']];
            $rows[] = ['Cache-Tag', $metadata['cache_tag']];
        }

        $this->table(['Field', 'Value'], $rows);

        return Command::SUCCESS;
    }

    private function renderResponse(Request $request): Response
    {
        $request->attributes->set(HtmlCacheMiddleware::SYNTHETIC_RENDER_ATTRIBUTE, true);
        $previousRequest = resolve('request');
        $cacheEnabled = config('capell-html-cache.enabled', true);
        app()->instance('request', $request);
        config()->set('capell-html-cache.enabled', false);

        try {
            $kernel = resolve(HttpKernel::class);
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);

            return $response;
        } finally {
            config()->set('capell-html-cache.enabled', $cacheEnabled);
            app()->instance('request', $previousRequest);
        }
    }

    /** @return array{status: int, cache_control: string, vary: string, set_cookie_count: int, surrogate_key: string, cache_tag: string} */
    private function responseMetadata(Response $response): array
    {
        return [
            'status' => $response->getStatusCode(),
            'cache_control' => (string) $response->headers->get('Cache-Control'),
            'vary' => (string) $response->headers->get('Vary'),
            'set_cookie_count' => count($response->headers->getCookies()),
            'surrogate_key' => (string) $response->headers->get('Surrogate-Key'),
            'cache_tag' => (string) $response->headers->get('Cache-Tag'),
        ];
    }

    private function url(): string
    {
        $url = $this->argument('url');
        $url = is_string($url) && $url !== '' ? $url : '/';

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        $baseUrl = rtrim((string) config('app.url', 'http://localhost'), '/');

        return $baseUrl . '/' . ltrim($url, '/');
    }

    private function pageUrl(Request $request): ?PageUrl
    {
        $site = $this->option('site');
        $path = '/' . ltrim($request->getPathInfo(), '/');

        return PageUrl::query()
            ->with(['siteDomain', 'pageable'])
            ->when(is_numeric($site), fn ($query) => $query->where('site_id', (int) $site))
            ->where('url', $path)
            ->first();
    }
}
