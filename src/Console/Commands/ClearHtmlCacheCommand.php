<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Console\Commands;

use Capell\HtmlCache\Actions\ClearAllHtmlCacheAction;
use Capell\HtmlCache\Support\Cache\PageCache;
use Illuminate\Console\Command;
use Throwable;

final class ClearHtmlCacheCommand extends Command
{
    protected $description = 'Clear all or part of the Capell HTML cache.';

    protected $signature = 'capell:html-cache:clear {slug? : URL slug of page or directory to delete} {--recursive}';

    public function handle(PageCache $cache): int
    {
        $slug = $this->argument('slug');

        if (! is_string($slug) || $slug === '') {
            try {
                $result = ClearAllHtmlCacheAction::run();
            } catch (Throwable $throwable) {
                $this->error(sprintf(
                    'Unable to clear the HTML cache. Check filesystem permissions for [%s]. %s',
                    public_path('page-cache'),
                    $throwable->getMessage(),
                ));

                return Command::FAILURE;
            }

            if (! $result->successful()) {
                $this->error(sprintf(
                    'Unable to clear the HTML cache. Check filesystem permissions for [%s]. Failed paths: %s',
                    public_path('page-cache'),
                    implode(', ', $result->failures()),
                ));

                return Command::FAILURE;
            }

            $this->info(sprintf('HTML cache cleared (%d item(s) removed).', $result->deletedCount()));

            return Command::SUCCESS;
        }

        $cleared = $this->option('recursive') === true ? $cache->clear($slug) : $cache->forget($slug);

        $cleared
            ? $this->info(sprintf('HTML cache cleared for "%s".', $slug))
            : $this->warn(sprintf('No HTML cache found for "%s".', $slug));

        return Command::SUCCESS;
    }
}
