<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Data;

use Capell\HtmlCache\Enums\MaintenanceSiteAction;
use Capell\HtmlCache\Enums\MaintenanceSiteStatus;
use Spatie\LaravelData\Data;

final class MaintenanceSiteStatusData extends Data
{
    /** @param list<MaintenanceCacheDomainData> $domains */
    public function __construct(
        public readonly int $siteId,
        public readonly string $siteName,
        public readonly bool $enabled,
        public readonly MaintenanceSiteStatus $status,
        public readonly array $domains,
        public readonly ?string $lastGeneratedAt = null,
    ) {}

    public function action(): ?MaintenanceSiteAction
    {
        return match ($this->status) {
            MaintenanceSiteStatus::Missing => MaintenanceSiteAction::Prepare,
            MaintenanceSiteStatus::Generated => MaintenanceSiteAction::EnableOverride,
            MaintenanceSiteStatus::Active => MaintenanceSiteAction::DisableOverride,
            MaintenanceSiteStatus::Preparing, MaintenanceSiteStatus::CoveredByGlobal => null,
        };
    }
}
