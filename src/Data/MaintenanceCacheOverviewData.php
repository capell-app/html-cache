<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Data;

use Capell\HtmlCache\Enums\MaintenanceCacheAttentionReason;
use Capell\HtmlCache\Enums\MaintenanceCacheStatus;
use Capell\HtmlCache\Enums\MaintenanceGlobalAction;
use Spatie\LaravelData\Data;

final class MaintenanceCacheOverviewData extends Data
{
    /**
     * @param  list<MaintenanceSiteStatusData>  $sites
     * @param  list<MaintenanceCacheAttentionReason>  $attentionReasons
     */
    public function __construct(
        public readonly MaintenanceCacheStatus $status,
        public readonly bool $globalManifestActive,
        public readonly bool $artisanMaintenanceActive,
        public readonly int $totalSites,
        public readonly int $readySites,
        public readonly array $sites,
        public readonly array $attentionReasons = [],
        public readonly ?string $currentRunId = null,
        public readonly ?string $currentRunStatus = null,
        public readonly int $currentRunTotalSites = 0,
        public readonly int $currentRunCompletedSites = 0,
        public readonly int $currentRunFailedSites = 0,
    ) {}

    public function hasAttentionReason(MaintenanceCacheAttentionReason $reason): bool
    {
        return in_array($reason, $this->attentionReasons, true);
    }

    /**
     * The single applicable global action for the current state, or null when
     * no global action makes sense (for example while a run is in flight, or
     * when Attention has no safe recovery action from this page alone).
     */
    public function primaryAction(): ?MaintenanceGlobalAction
    {
        return match (true) {
            $this->status === MaintenanceCacheStatus::Off => MaintenanceGlobalAction::Prepare,
            $this->status === MaintenanceCacheStatus::Ready => MaintenanceGlobalAction::ReviewAndEnable,
            $this->status === MaintenanceCacheStatus::Active => MaintenanceGlobalAction::ExitMaintenance,
            $this->status === MaintenanceCacheStatus::Attention
                && $this->hasAttentionReason(MaintenanceCacheAttentionReason::ArtisanStateDrift) => MaintenanceGlobalAction::ExitMaintenance,
            $this->status === MaintenanceCacheStatus::Attention
                && $this->hasAttentionReason(MaintenanceCacheAttentionReason::GenerationFailed) => MaintenanceGlobalAction::Prepare,
            default => null,
        };
    }
}
