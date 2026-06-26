<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Cache;

use Illuminate\Http\Request;

/**
 * Recognises GET requests whose only query parameters are allow-listed
 * stateless-pagination keys (e.g. ?page=2, ?theme_tag=foo). Such requests stay
 * eligible for the shared HTML cache; their query state is folded into the cache
 * key so each page/filter variant is stored separately instead of colliding on
 * the path-only key.
 *
 * Single source of truth shared by HtmlCacheMiddleware, PageCache, and
 * BuildHtmlCacheEligibilityReportAction so the veto rules cannot drift.
 */
final class StatelessPaginationRequest
{
    public const string FRAGMENT_HEADER = 'X-Fragment';

    public static function enabled(): bool
    {
        return config('capell-html-cache.stateless_pagination.enabled', true) === true
            && config('capell-html-cache.enabled', true) === true;
    }

    /**
     * True when the request carries query params and every one of them is on the
     * allow-list — i.e. it is a cacheable pagination/filter variant, not an
     * arbitrary querystring that must bypass the cache.
     */
    public static function isCacheableVariant(Request $request): bool
    {
        if (! self::enabled() || $request->query->count() === 0) {
            return false;
        }

        $allowed = self::allowedParams();

        if ($allowed === []) {
            return false;
        }

        foreach (array_keys($request->query->all()) as $key) {
            if (! in_array((string) $key, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A short, deterministic cache-key suffix derived from the allow-listed query
     * params (sorted) plus a fragment marker. Empty string when there is nothing
     * to vary on, so non-paginated URLs keep their existing cache filename.
     */
    public static function cacheKeySuffix(Request $request): string
    {
        $parts = [];

        if (self::isCacheableVariant($request)) {
            $params = array_filter(
                $request->query->all(),
                static fn (string $key): bool => in_array($key, self::allowedParams(), true),
                ARRAY_FILTER_USE_KEY,
            );
            ksort($params);
            $parts[] = http_build_query($params);
        }

        if ($request->headers->has(self::FRAGMENT_HEADER)) {
            $parts[] = 'fragment=' . (string) $request->headers->get(self::FRAGMENT_HEADER);
        }

        if ($parts === []) {
            return '';
        }

        return '~' . mb_substr(hash('xxh128', implode('|', $parts)), 0, 16);
    }

    /**
     * @return list<string>
     */
    private static function allowedParams(): array
    {
        $params = config('capell-html-cache.stateless_pagination.params', []);

        return is_array($params) ? array_values(array_filter($params, is_string(...))) : [];
    }
}
