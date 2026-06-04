<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Cache;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class ConfiguredHtmlCacheBypassRules
{
    public function shouldBypass(Request $request): bool
    {
        return $this->pathMatches($request)
            || $this->cookieMatches($request);
    }

    private function pathMatches(Request $request): bool
    {
        $path = $this->normalizedPath($request);

        foreach ($this->configuredPatterns('capell-html-cache.bypass.paths') as $pattern) {
            if ($this->matchesPathPattern($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    private function cookieMatches(Request $request): bool
    {
        $cookieNames = $this->cookieNames($request);

        foreach ($this->configuredPatterns('capell-html-cache.bypass.cookies') as $pattern) {
            foreach ($cookieNames as $cookieName) {
                if (Str::is($pattern, $cookieName)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function matchesPathPattern(string $pattern, string $path): bool
    {
        $normalizedPattern = $this->normalizePathPattern($pattern);

        return Str::is($normalizedPattern, $path)
            || Str::is(ltrim($normalizedPattern, '/'), ltrim($path, '/'));
    }

    private function normalizedPath(Request $request): string
    {
        $path = '/' . trim($request->path(), '/');

        return $path === '/.' ? '/' : $path;
    }

    private function normalizePathPattern(string $pattern): string
    {
        if ($pattern === '/' || $pattern === '*') {
            return $pattern;
        }

        return '/' . ltrim($pattern, '/');
    }

    /**
     * @return list<string>
     */
    private function configuredPatterns(string $configKey): array
    {
        $patterns = config($configKey, []);

        if (! is_array($patterns)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn (mixed $pattern): ?string => is_string($pattern) && trim($pattern) !== ''
                    ? trim($pattern)
                    : null,
                $patterns,
            ),
            static fn (?string $pattern): bool => $pattern !== null,
        ));
    }

    /**
     * @return list<string>
     */
    private function cookieNames(Request $request): array
    {
        $names = array_keys($request->cookies->all());
        $cookieHeader = $request->headers->get('Cookie');

        if (is_string($cookieHeader) && $cookieHeader !== '') {
            foreach (explode(';', $cookieHeader) as $cookiePair) {
                $cookieName = trim(explode('=', $cookiePair, 2)[0] ?? '');

                if ($cookieName !== '') {
                    $names[] = $cookieName;
                }
            }
        }

        return array_values(array_unique($names));
    }
}
