<x-filament-panels::page>
    <div class="space-y-4">
        <section
            class="rounded-lg border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900"
        >
            <dl class="grid gap-3 md:grid-cols-2">
                <div>
                    <dt
                        class="text-sm font-medium text-gray-500 dark:text-gray-400"
                    >
                        {{ __('capell-html-cache::admin.global_maintenance') }}
                    </dt>
                    <dd class="mt-1 text-sm font-semibold">
                        {{ $this->manifest()['global_active'] ? __('capell-html-cache::admin.active') : __('capell-html-cache::admin.inactive') }}
                    </dd>
                </div>
                <div>
                    <dt
                        class="text-sm font-medium text-gray-500 dark:text-gray-400"
                    >
                        {{ __('capell-html-cache::admin.manifest_path') }}
                    </dt>
                    <dd class="mt-1 break-all text-sm">
                        {{ resolve(MaintenanceManifestStore::class)->path() }}
                    </dd>
                </div>
            </dl>
        </section>

        <section class="space-y-3">
            @foreach ($this->sites() as $site)
                @php
                    $siteManifest = data_get($this->manifest(), 'sites.' . $site->id, []);
                    $domains = $siteManifest['domains'] ?? [];
                @endphp

                <article
                    class="rounded-lg border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900"
                >
                    <div
                        class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between"
                    >
                        <div>
                            <h2 class="text-base font-semibold">
                                {{ $site->name }}
                            </h2>
                            <p class="text-sm text-gray-600 dark:text-gray-400">
                                {{ data_get($siteManifest, 'active') ? __('capell-html-cache::admin.site_override_active') : __('capell-html-cache::admin.site_override_inactive') }}
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <x-filament::button
                                size="sm"
                                color="gray"
                                wire:click="generateSite({{ $site->id }})"
                            >
                                {{ __('capell-html-cache::admin.generate') }}
                            </x-filament::button>
                            <x-filament::button
                                size="sm"
                                wire:click="toggleSite({{ $site->id }})"
                            >
                                {{ __('capell-html-cache::admin.toggle_site_override') }}
                            </x-filament::button>
                        </div>
                    </div>

                    <ul
                        class="mt-3 space-y-1 text-sm text-gray-600 dark:text-gray-400"
                    >
                        @forelse ($domains as $domain)
                            <li class="break-all">
                                {{ $domain['scheme'] }}://{{ $domain['domain'] }}{{ $domain['path'] ?? '/' }}
                                <span class="text-gray-400">
                                    -> {{ $domain['file'] }}
                                </span>
                            </li>
                        @empty
                            <li>
                                {{ __('capell-html-cache::admin.no_maintenance_cache') }}
                            </li>
                        @endforelse
                    </ul>
                </article>
            @endforeach
        </section>
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
