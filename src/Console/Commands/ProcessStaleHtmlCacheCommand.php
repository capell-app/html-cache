<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Console\Commands;

use Capell\HtmlCache\Actions\ProcessStaleHtmlCacheAction;
use Illuminate\Console\Command;

final class ProcessStaleHtmlCacheCommand extends Command
{
    protected $description = 'Refresh stale public HTML cache entries queued by scheduled invalidation.';

    protected $signature = 'capell:html-cache:process-stale {--limit= : Maximum stale URLs to process}';

    public function handle(): int
    {
        $limit = $this->limit();
        $processed = ProcessStaleHtmlCacheAction::run($limit);

        $this->info(sprintf('Processed %d stale HTML cache URL(s).', $processed));

        return Command::SUCCESS;
    }

    private function limit(): ?int
    {
        $limit = $this->option('limit');

        if (! is_numeric($limit)) {
            return null;
        }

        return max(1, (int) $limit);
    }
}
