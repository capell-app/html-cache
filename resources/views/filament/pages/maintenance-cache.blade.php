@php
    use Capell\Frontend\Support\Maintenance\MaintenanceManifestStore;
    use Capell\HtmlCache\Enums\MaintenanceCacheStatus;
    use Capell\HtmlCache\Enums\MaintenanceSiteAction;

    $overview = $this->overview();

    $statusColor = match ($overview->status) {
        MaintenanceCacheStatus::Off => 'gray',
        MaintenanceCacheStatus::Preparing => 'info',
        MaintenanceCacheStatus::Ready => 'warning',
        MaintenanceCacheStatus::Active => 'danger',
        MaintenanceCacheStatus::Attention => 'danger',
    };
@endphp

<x-filament-panels::page>
    <div class="space-y-4">
        <section
            class="rounded-lg border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900"
        >
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <x-filament::badge :color="$statusColor" wire:key="maintenance-status-badge">
                        {{ $overview->status->label() }}
                    </x-filament::badge>

                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                        {{ __('capell-html-cache::admin.maintenance_scope_summary', [
                            'ready' => $overview->readySites,
                            'total' => $overview->totalSites,
                        ]) }}
                    </p>
                </div>

                @if ($overview->status === MaintenanceCacheStatus::Preparing)
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        {{ __('capell-html-cache::admin.maintenance_run_progress', [
                            'completed' => $overview->currentRunCompletedSites,
                            'total' => $overview->currentRunTotalSites,
                        ]) }}
                    </p>
                @endif
            </div>

            @if ($overview->attentionReasons !== [])
                <ul class="mt-3 space-y-1 text-sm text-amber-700 dark:text-amber-400">
                    @foreach ($overview->attentionReasons as $reason)
                        <li>{{ $reason->label() }}</li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="space-y-3">
            @forelse ($overview->sites as $site)
                <article
                    class="rounded-lg border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900"
                    wire:key="maintenance-site-{{ $site->siteId }}"
                >
                    <div
                        class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between"
                    >
                        <div>
                            <h2 class="text-base font-semibold">
                                {{ $site->siteName }}
                            </h2>
                            <p class="text-sm text-gray-600 dark:text-gray-400">
                                {{ $site->status->label() }}
                            </p>
                            @if ($site->lastGeneratedAt)
                                <p class="text-xs text-gray-500 dark:text-gray-500">
                                    {{ __('capell-html-cache::admin.maintenance_last_generated_at', ['time' => $site->lastGeneratedAt]) }}
                                </p>
                            @endif
                        </div>

                        @if ($site->action() === MaintenanceSiteAction::Prepare)
                            <x-filament::button
                                size="sm"
                                color="gray"
                                wire:click="prepareSite({{ $site->siteId }})"
                            >
                                {{ MaintenanceSiteAction::Prepare->label() }}
                            </x-filament::button>
                        @elseif ($site->action() === MaintenanceSiteAction::EnableOverride)
                            <x-filament::button
                                size="sm"
                                wire:click="enableSiteOverride({{ $site->siteId }})"
                            >
                                {{ MaintenanceSiteAction::EnableOverride->label() }}
                            </x-filament::button>
                        @elseif ($site->action() === MaintenanceSiteAction::DisableOverride)
                            <x-filament::button
                                size="sm"
                                color="danger"
                                wire:click="disableSiteOverride({{ $site->siteId }})"
                            >
                                {{ MaintenanceSiteAction::DisableOverride->label() }}
                            </x-filament::button>
                        @endif
                    </div>

                    <ul
                        class="mt-3 space-y-1 text-sm text-gray-600 dark:text-gray-400"
                    >
                        @forelse ($site->domains as $domain)
                            <li class="break-all">
                                {{ $domain->url() }}
                            </li>
                        @empty
                            <li>
                                {{ __('capell-html-cache::admin.no_maintenance_cache') }}
                            </li>
                        @endforelse
                    </ul>
                </article>
            @empty
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    {{ __('capell-html-cache::admin.maintenance_no_accessible_sites') }}
                </p>
            @endforelse
        </section>

        <details class="rounded-lg border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <summary class="cursor-pointer text-sm font-medium text-gray-600 dark:text-gray-400">
                {{ __('capell-html-cache::admin.technical_details') }}
            </summary>

            <dl class="mt-3 grid gap-3 md:grid-cols-2">
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">
                        {{ __('capell-html-cache::admin.manifest_path') }}
                    </dt>
                    <dd class="mt-1 text-sm break-all">
                        {{ resolve(MaintenanceManifestStore::class)->path() }}
                    </dd>
                </div>
            </dl>

            <ul class="mt-3 space-y-2 text-sm text-gray-600 dark:text-gray-400">
                @foreach ($overview->sites as $site)
                    @foreach ($site->domains as $domain)
                        <li class="break-all">
                            {{ $site->siteName }}: {{ $domain->url() }}
                            <span class="text-gray-400">-> {{ $domain->file }}</span>
                        </li>
                    @endforeach
                @endforeach
            </ul>
        </details>
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
