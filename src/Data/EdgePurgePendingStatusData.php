<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Data;

final readonly class EdgePurgePendingStatusData
{
    public function __construct(
        public int $count = 0,
        public bool $purgeAll = false,
        public ?int $oldestQueuedAt = null,
        public bool $hasDrainer = false,
        public ?string $escalationReason = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->count === 0 && ! $this->purgeAll;
    }

    public function oldestAgeSeconds(int $now): ?int
    {
        return $this->oldestQueuedAt === null ? null : max(0, $now - $this->oldestQueuedAt);
    }
}
