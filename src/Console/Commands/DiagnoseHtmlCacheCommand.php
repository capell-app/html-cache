<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Console\Commands;

use Capell\Core\Models\PageUrl;
use Capell\HtmlCache\Actions\BuildHtmlCacheEligibilityReportAction;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

final class DiagnoseHtmlCacheCommand extends Command
{
    protected $description = 'Diagnose why a public URL is or is not eligible for the Capell HTML cache.';

    protected $signature = 'capell:html-cache:diagnose
        {url? : Absolute URL or path to inspect}
        {--site= : Optional site id used to resolve a PageUrl row for diagnostics}
        {--json : Output the report as JSON}';

    public function handle(): int
    {
        $url = $this->url();
        $request = Request::create($url, \Symfony\Component\HttpFoundation\Request::METHOD_GET);
        $pageUrl = $this->pageUrl($request);
        $report = BuildHtmlCacheEligibilityReportAction::run($request, pageUrl: $pageUrl);

        if ($this->option('json') === true) {
            $this->line(json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        $this->table(['Field', 'Value'], [
            ['URL', $report->url],
            ['Eligible', $report->eligible ? 'yes' : 'no'],
            ['Reasons', $report->reasonCodes() === [] ? 'none' : implode(', ', $report->reasonCodes())],
            ['Blocking packages', $report->blockingPackages === [] ? 'none' : implode(', ', $report->blockingPackages)],
            ['Cache tags', $report->cacheTags === [] ? 'none' : implode(', ', $report->cacheTags)],
            ['Cache state', $report->cacheState],
            ['Stale', $report->stale ? 'yes' : 'no'],
            ['Last cached at', $report->lastCachedAt ?? 'never'],
        ]);

        return Command::SUCCESS;
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
