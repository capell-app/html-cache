<x-filament-panels::page>
    <x-filament::section>
        <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('capell-html-cache::admin.uncached_scope') }}</p>
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ __('capell-html-cache::admin.uncached_metrics_gap') }}</p>
    </x-filament::section>

    <x-filament::section>
        @include('capell-html-cache::filament.pages.partials.uncached-pages-table')
    </x-filament::section>
</x-filament-panels::page>
