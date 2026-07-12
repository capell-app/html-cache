<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Data;

use Capell\HtmlCache\Enums\HtmlCacheEligibilityReason;
use Override;
use Spatie\LaravelData\Data;

final class HtmlCacheEligibilityReportData extends Data
{
    /**
     * @param  list<HtmlCacheEligibilityReason>  $reasons
     * @param  list<string>  $blockingPackages
     * @param  list<string>  $cacheTags
     */
    public function __construct(
        public readonly string $url,
        public readonly bool $eligible,
        public readonly array $reasons,
        public readonly array $blockingPackages = [],
        public readonly array $cacheTags = [],
        public readonly string $cacheState = 'unknown',
        public readonly bool $stale = false,
        public readonly ?string $lastCachedAt = null,
    ) {}

    public function hasReason(HtmlCacheEligibilityReason $reason): bool
    {
        return in_array($reason, $this->reasons, true);
    }

    /**
     * @return list<string>
     */
    public function reasonCodes(): array
    {
        return array_map(
            static fn (HtmlCacheEligibilityReason $reason): string => $reason->value,
            $this->reasons,
        );
    }

    /** @return array<string, mixed> */
    #[Override]
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'eligible' => $this->eligible,
            'reasons' => $this->reasonCodes(),
            'blockingPackages' => $this->blockingPackages,
            'cacheTags' => $this->cacheTags,
            'cacheState' => $this->cacheState,
            'stale' => $this->stale,
            'lastCachedAt' => $this->lastCachedAt,
        ];
    }
}
