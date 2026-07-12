<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Extensions;

use Capell\Frontend\Actions\Performance\RecordExtensionRenderContributionAction;
use Capell\Frontend\Data\Performance\ExtensionRenderContributionData;
use Capell\HtmlCache\Enums\HtmlCacheEligibilityReason;

final class ExtensionCacheSafetyResolver
{
    public function isPublicCacheSafe(): bool
    {
        return $this->blockingContributions() === [];
    }

    /**
     * @return list<ExtensionRenderContributionData>
     */
    public function blockingContributions(): array
    {
        return array_values(collect($this->contributions())
            ->filter(fn (ExtensionRenderContributionData $contribution): bool => ! $contribution->cacheable || $contribution->sensitiveOutput)
            ->values()
            ->all());
    }

    /**
     * @return list<string>
     */
    public function blockingPackageNames(): array
    {
        return array_values(collect($this->blockingContributions())
            ->map(fn (ExtensionRenderContributionData $contribution): string => $contribution->packageName)
            ->unique()
            ->values()
            ->all());
    }

    /**
     * @return list<HtmlCacheEligibilityReason>
     */
    public function blockingReasonCodes(): array
    {
        $reasons = [];

        foreach ($this->blockingContributions() as $contribution) {
            if (! $contribution->cacheable) {
                $reasons[] = HtmlCacheEligibilityReason::PackageCacheBlocking;
            }

            if ($contribution->sensitiveOutput) {
                $reasons[] = HtmlCacheEligibilityReason::PackageSensitiveOutput;
            }
        }

        return array_values(array_unique($reasons, SORT_REGULAR));
    }

    /** @return list<string> */
    public function cacheTags(): array
    {
        return array_values(collect($this->contributions())
            ->flatMap(fn (ExtensionRenderContributionData $contribution): array => $contribution->cacheTags)
            ->filter(fn (mixed $tag): bool => $tag !== '')
            ->map(fn (string $tag): string => $this->normalizeTag($tag))
            ->unique()
            ->values()
            ->all());
    }

    /** @return list<ExtensionRenderContributionData> */
    private function contributions(): array
    {
        if (! class_exists(RecordExtensionRenderContributionAction::class)) {
            return [];
        }

        return resolve(RecordExtensionRenderContributionAction::class)->recorded();
    }

    private function normalizeTag(string $tag): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $tag), '-');
    }
}
