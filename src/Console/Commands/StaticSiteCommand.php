<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Console\Commands;

use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\HtmlCache\Support\StaticSite\StaticSiteGenerator;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\Console\Helper\ProgressBar;

final class StaticSiteCommand extends Command
{
    protected $description = 'Generate static HTML cache files for the specified site or all sites if none is specified.';

    protected $signature = 'capell:static-site {--site=} {--internal : Render URLs through the current Laravel kernel} {--refresh : Delete affected HTML cache files before rendering}';

    private ?ProgressBar $progressBar = null;

    public function handle(): int
    {
        $this->line('Starting static HTML generator' . PHP_EOL);

        $sites = $this->getSites();
        $previousInternalRequests = config('capell-html-cache.static_generation.internal_requests', false);

        if ($this->option('internal') === true) {
            config()->set('capell-html-cache.static_generation.internal_requests', true);
        }

        try {
            $sites->each(function (Site $site): void {
                (new StaticSiteGenerator(
                    site: $site,
                    refresh: $this->option('refresh') === true,
                ))->process(
                    start: function (Site $site, SiteDomain $domain): void {
                        $this->newLine();
                        $this->comment(sprintf('%s(%d) - %s', $site->name, $site->id, $domain->language->name));
                    },
                    prepare: function (int $total, SiteDomain $siteDomain): void {
                        $this->line(sprintf('Total URLs: %d for domain: %s', $total, $siteDomain->full_url));
                        $this->progressBar = $this->output->createProgressBar($total);
                        $this->progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% %message%');
                        $this->progressBar->setMessage('');
                    },
                    checkpoint: function (string $url): void {
                        $this->progressBar?->setMessage($url);
                        $this->progressBar?->advance();
                    },
                    end: function (): void {
                        $this->progressBar?->setMessage('');
                        $this->progressBar?->finish();
                        $this->newLine();
                    },
                );
            });
        } finally {
            config()->set('capell-html-cache.static_generation.internal_requests', $previousInternalRequests);
        }

        $this->newLine();
        $this->info('Static HTML generated successfully');

        return Command::SUCCESS;
    }

    /** @return Collection<int, Site> */
    private function getSites(): Collection
    {
        $query = Site::query()
            ->with('siteDomains.language')
            ->enabled();

        if ($this->option('site') !== null) {
            $query->where('id', $this->option('site'));
        }

        return $query->get();
    }
}
