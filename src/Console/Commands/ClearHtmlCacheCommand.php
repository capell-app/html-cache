<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Console\Commands;

use Capell\HtmlCache\Actions\ClearAllHtmlCacheAction;
use Capell\HtmlCache\Support\Cache\PageCache;
use Illuminate\Console\Command;

final class ClearHtmlCacheCommand extends Command
{
    protected $description = 'Clear all or part of the Capell HTML cache.';

    protected $signature = 'capell:html-cache:clear {slug? : URL slug of page or directory to delete} {--recursive}';

    public function handle(PageCache $cache): int
    {
        $slug = $this->argument('slug');

        if (! is_string($slug) || $slug === '') {
            ClearAllHtmlCacheAction::run();
            $this->info('HTML cache cleared.');

            return Command::SUCCESS;
        }

        $cleared = $this->option('recursive') === true ? $cache->clear($slug) : $cache->forget($slug);

        $cleared
            ? $this->info(sprintf('HTML cache cleared for "%s".', $slug))
            : $this->warn(sprintf('No HTML cache found for "%s".', $slug));

        return Command::SUCCESS;
    }
}
