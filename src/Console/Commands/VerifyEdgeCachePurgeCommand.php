<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Console\Commands;

use Capell\HtmlCache\Actions\InspectEdgeCachePurgeReadinessAction;
use Illuminate\Console\Command;

final class VerifyEdgeCachePurgeCommand extends Command
{
    protected $signature = 'capell:html-cache:edge-purge:verify
        {--driver= : Require this exact purge driver}';

    protected $description = 'Verify edge-purge configuration without sending credentials or purging cached content.';

    public function handle(InspectEdgeCachePurgeReadinessAction $inspectReadiness): int
    {
        $expectedDriver = $this->option('driver');
        $readiness = $inspectReadiness->handle(is_string($expectedDriver) && $expectedDriver !== ''
            ? $expectedDriver
            : null);

        if (! $readiness->isReady()) {
            foreach ($readiness->errors as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Edge purge configuration is ready (driver: %s, required: %s). No purge request was sent.',
            $readiness->driver,
            $readiness->required ? 'yes' : 'no',
        ));

        return self::SUCCESS;
    }
}
