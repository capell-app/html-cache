<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Console\Commands;

use Capell\HtmlCache\Actions\MarkAllCachedUrlsStaleAction;
use Capell\HtmlCache\Actions\ProcessStaleHtmlCacheAction;
use Capell\HtmlCache\Support\Cache\PageCache;
use Illuminate\Console\Command;
use Throwable;

final class ClearHtmlCacheCommand extends Command
{
    protected $description = 'Queue a full HTML cache refresh or clear a specific cache path.';

    protected $signature = 'capell:html-cache:clear {slug? : URL slug of page or directory to delete} {--recursive} {--process : Process queued stale URLs immediately in this CLI process}';

    public function handle(PageCache $cache): int
    {
        $slug = $this->argument('slug');

        if (! is_string($slug) || $slug === '') {
            try {
                $marked = MarkAllCachedUrlsStaleAction::run('manual_clear');
            } catch (Throwable $throwable) {
                $this->error(sprintf(
                    'Unable to clear the HTML cache. Check filesystem permissions for [%s]. %s',
                    public_path('page-cache'),
                    $throwable->getMessage(),
                ));

                return Command::FAILURE;
            }

            $this->info(sprintf(
                'Marked %d URL(s) stale. Nothing has been regenerated yet; the old HTML is still being served.',
                $marked,
            ));

            if ($this->option('process') === true) {
                $processed = ProcessStaleHtmlCacheAction::run();
                $this->info(sprintf('Processed %d stale HTML cache URL(s).', $processed));
            } else {
                $this->line('Run capell:html-cache:process-stale (or pass --process) to regenerate them.');
            }

            return Command::SUCCESS;
        }

        $cleared = $this->option('recursive') === true ? $cache->clear($slug) : $cache->forget($slug);

        $cleared
            ? $this->info(sprintf('HTML cache cleared for "%s".', $slug))
            : $this->warn(sprintf('No HTML cache found for "%s".', $slug));

        return Command::SUCCESS;
    }
}
