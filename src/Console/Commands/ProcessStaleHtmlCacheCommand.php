<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Console\Commands;

use Capell\HtmlCache\Actions\ProcessStaleHtmlCacheAction;
use Illuminate\Console\Command;

final class ProcessStaleHtmlCacheCommand extends Command
{
    protected $description = 'Refresh stale public HTML cache entries queued by scheduled invalidation.';

    protected $signature = 'capell:html-cache:process-stale {--limit= : Maximum stale URLs to process} {--suppress-inline-edge-purge : Leave edge purging to the caller after origin verification}';

    public function handle(): int
    {
        $limit = $this->limit();
        $result = ProcessStaleHtmlCacheAction::run($limit, (bool) $this->option('suppress-inline-edge-purge'));

        $summary = (string) __('capell-html-cache::cache.refresh_summary', [
            'attempted' => $result->attempted,
            'succeeded' => $result->succeeded,
            'failed' => $result->failed,
            'deferred' => $result->deferred,
            'not_applicable' => $result->notApplicable,
        ]);
        $result->successful() ? $this->info($summary) : $this->error($summary);

        return $result->successful() ? Command::SUCCESS : Command::FAILURE;
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
