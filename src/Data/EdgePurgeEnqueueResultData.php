<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Data;

final readonly class EdgePurgeEnqueueResultData
{
    /**
     * @param  string|null  $drainerToken  set when this enqueue claimed the zone's drainer and must dispatch it
     */
    public function __construct(
        public int $pendingCount,
        public ?string $drainerToken = null,
        public ?string $escalationReason = null,
    ) {}
}
