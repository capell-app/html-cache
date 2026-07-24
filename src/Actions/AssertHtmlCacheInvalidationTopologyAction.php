<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Core\Support\Hosting\MultiNodeTopologyGuard;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** @method static void run() */
final class AssertHtmlCacheInvalidationTopologyAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly MultiNodeTopologyGuard $topologyGuard,
    ) {}

    public function handle(): void
    {
        if ($this->hasCrossNodeInvalidation()) {
            return;
        }

        $this->topologyGuard->assertFilesystemDiskIsShared(
            disk: 'page_cache',
            operation: 'HTML page cache invalidation',
        );
    }

    private function hasCrossNodeInvalidation(): bool
    {
        if (config('capell-html-cache.deployment.shared_page_cache', false) === true) {
            return true;
        }

        return in_array(
            config('capell-html-cache.purge.driver', 'null'),
            ['cloudflare', 'http'],
            true,
        );
    }
}
