<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Extensions;

use Capell\Frontend\Actions\Performance\RecordExtensionRenderContributionAction;
use Capell\Frontend\Data\Performance\ExtensionRenderContributionData;

final class ExtensionCacheSafetyResolver
{
    public function isPublicCacheSafe(): bool
    {
        foreach ($this->contributions() as $contribution) {
            if (! $contribution->cacheable || $contribution->sensitiveOutput) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    public function cacheTags(): array
    {
        return collect($this->contributions())
            ->flatMap(fn (ExtensionRenderContributionData $contribution): array => $contribution->cacheTags)
            ->filter(fn (mixed $tag): bool => $tag !== '')
            ->map(fn (string $tag): string => $this->normalizeTag($tag))
            ->unique()
            ->values()
            ->all();
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
