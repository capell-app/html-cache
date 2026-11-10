<div class="overflow-x-auto">
    <table class="w-full text-left text-sm">
        <caption class="sr-only">
            {{ __('capell-html-cache::admin.uncached_pages') }}
        </caption>
        <thead class="text-gray-950 dark:text-white">
            <tr>
                @foreach (['url', 'site', 'uncached_reason', 'uncached_last_render', 'cached_at', 'uncached_hit_rate', 'uncached_cached_hits'] as $column)
                    <th
                        scope="col"
                        class="px-3 py-3"
                    >
                        {{ __('capell-html-cache::admin.' . $column) }}
                    </th>
                @endforeach
            </tr>
        </thead>
        <tbody
            class="divide-y divide-gray-200 text-gray-700 dark:divide-gray-700 dark:text-gray-300"
        >
            @forelse ($overview->pages as $page)
                <tr>
                    <th
                        scope="row"
                        class="px-3 py-3 font-normal break-all"
                    >
                        {{ $page->url }}
                    </th>
                    <td class="px-3 py-3">{{ $page->site }}</td>
                    <td class="px-3 py-3">
                        <ul class="space-y-1">
                            @foreach ($page->reasons as $reason)
                                <li><code>{{ $reason }}</code></li>
                            @endforeach
                        </ul>
                        @if ($page->cacheIssues !== [])
                            <p class="mt-2">{{ __('capell-html-cache::admin.uncached_cache_state') }}</p>
                            <ul class="space-y-1">
                                @foreach ($page->cacheIssues as $issue)
                                    <li><code>{{ $issue }}</code></li>
                                @endforeach
                            </ul>
                        @endif
                        @if ($page->refreshStatus !== null)
                            <p class="mt-2">{{ __('capell-html-cache::admin.uncached_refresh_status') }}: {{ __('capell-html-cache::dashboard.status_' . $page->refreshStatus) }}</p>
                        @endif
                        @if ($page->lastError !== null)
                            <p class="mt-2 break-all">{{ __('capell-html-cache::admin.uncached_recorded_error') }}: {{ $page->lastError }}</p>
                        @endif
                    </td>
                    <td class="px-3 py-3">
                        {{ $page->lastRenderMilliseconds !== null ? \Illuminate\Support\Number::format($page->lastRenderMilliseconds, precision: 2, locale: app()->getLocale()) : '' }}
                    </td>
                    <td class="px-3 py-3">
                        @if ($page->lastCachedAt !== null)
                            <time
                                datetime="{{ $page->lastCachedAt }}"
                                >{{ $page->lastCachedAt }}</time
                            >
                        @endif
                    </td>
                    <td class="px-3 py-3">
                        @if ($page->hitRate !== null)
                            <span
                                >{{ \Illuminate\Support\Number::format($page->hitRate, precision: 2, locale: app()->getLocale()) . '%' }}</span
                            >
                            @if ($page->metricsObservedSince !== null)
                                <small class="block">
                                    <time
                                        datetime="{{ $page->metricsObservedSince }}"
                                        >{{ __('capell-html-cache::admin.uncached_observed_since', ['time' => $page->metricsObservedSince]) }}</time
                                    >
                                </small>
                            @endif
                        @endif
                    </td>
                    <td class="px-3 py-3">
                        {{ \Illuminate\Support\Number::format($page->cachedHits, precision: 0, locale: app()->getLocale()) }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td
                        colspan="7"
                        class="px-3 py-6"
                    >
                        {{ __('capell-html-cache::admin.uncached_empty') }}
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
