<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Data;

/**
 * The outcome of withdrawing one model's public HTML: either the URLs that
 * render it were withdrawn, or its dependencies were not known and the whole
 * cache was cleared instead.
 */
final readonly class HtmlCacheWithdrawalData
{
    public function __construct(
        public bool $targeted,
        public int $withdrawnUrls = 0,
        public ?string $fullClearReason = null,
    ) {}

    public static function targeted(int $withdrawnUrls): self
    {
        return new self(targeted: true, withdrawnUrls: $withdrawnUrls);
    }

    public static function fullClear(string $reason): self
    {
        return new self(targeted: false, fullClearReason: $reason);
    }
}
