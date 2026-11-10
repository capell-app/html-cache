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
        // An edge purge driver empties the CDN, not the peer origins: a clear job
        // deletes files on the node that runs it only, so the next edge miss can
        // still be served withdrawn HTML from another node's local cache. Only
        // shared origin storage makes multi-node invalidation complete.
        if (config('capell-html-cache.deployment.shared_page_cache', false) === true) {
            return;
        }

        $this->topologyGuard->assertFilesystemDiskIsShared(
            disk: 'page_cache',
            operation: 'HTML page cache invalidation',
        );
    }
}
