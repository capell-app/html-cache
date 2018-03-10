<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Cache;

use Capell\Core\Models\SiteDomain;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class HtmlCachePathResolver
{
    private const int MAX_PATH_SEGMENT_LENGTH = 255;

    private const int MAX_RELATIVE_PATH_LENGTH = 2048;

    /**
     * The stored key is shared by publication, reads and every URL invalidation.
     * Laravel decodes path segments and aliases index; raw URL paths are not keys.
     */
    public function relativePathForRequest(Request $request, string $extension = '.html'): ?string
    {
        if ($request->query->count() > 0 && ! StatelessPaginationRequest::hasStoredVariantKey($request)) {
            return null;
        }

        $segments = $this->safeRequestSegments($request);

        if ($segments === null) {
            return null;
        }

        $filename = array_pop($segments);
        $filename = in_array($filename, [null, '', 'index'], true) ? 'pc__index__pc' : $filename;

        return implode('/', [...$segments, $filename . StatelessPaginationRequest::cacheKeySuffix($request) . $extension]);
    }

    public function pathForRequestUrl(string|Request $url, ?SiteDomain $siteDomain = null, bool $error = false): string
    {
        $request = $url instanceof Request ? $url : Request::create($url);

        // An unsupported query has no stored key; a missing suffix must never alias canonical files.
        if ($request->query->count() > 0 && ! StatelessPaginationRequest::hasStoredVariantKey($request)) {
            throw new InvalidArgumentException('Unsupported query parameters have no HTML cache path.');
        }

        $path = $this->relativePathForRequest($request, $error ? PageCache::ERROR_EXTENSION : '.html');

        if ($path === null) {
            throw new InvalidArgumentException('Unsafe URL for cache path.');
        }

        return $this->rootForRequest($request, $siteDomain) . '/' . $path;
    }

    public function hasSafeKey(string|Request $url): bool
    {
        return $this->relativePathForRequest($url instanceof Request ? $url : Request::create($url)) !== null;
    }

    public function rootForRequest(Request $request, ?SiteDomain $siteDomain = null): string
    {
        $scheme = $siteDomain instanceof SiteDomain ? $siteDomain->scheme : $request->getScheme();
        $domain = $siteDomain instanceof SiteDomain ? $siteDomain->domain : $request->getHost();
        $this->assertSafeSegment('scheme', $scheme);
        $this->assertSafeSegment('domain', $domain);

        return sprintf('%s.%s', $scheme, $domain);
    }

    public function directoryForPath(string $path): string
    {
        $request = Request::create('/' . ltrim($path, '/'));
        $segments = $this->safeRequestSegments($request);

        if ($request->query->count() > 0 || $segments === null) {
            throw new InvalidArgumentException('Unsafe directory for cache path.');
        }

        return implode('/', $segments);
    }

    public function directoryForSiteDomain(SiteDomain $siteDomain): string
    {
        return rtrim($this->rootForRequest(Request::create('/'), $siteDomain) . '/' . $this->directoryForPath($siteDomain->path ?? ''), '/');
    }

    public function pathForUrl(string $url, SiteDomain $siteDomain, bool $error = false): string
    {
        $this->assertSafeSegment('scheme', $siteDomain->scheme);
        $this->assertSafeSegment('domain', $siteDomain->domain);
        $this->assertSafePath('site domain path', $siteDomain->path ?? '/');
        $this->assertSafePath('URL', $url);

        $absoluteUrl = sprintf('%s://%s%s/%s', $siteDomain->scheme, $siteDomain->domain, rtrim($siteDomain->path ?? '', '/'), ltrim($url, '/'));

        return $this->pathForRequestUrl($absoluteUrl, $siteDomain, $error);
    }

    /** @return list<string> */
    public function historicalPathsForStoredPath(string $path, Request $request, ?SiteDomain $siteDomain = null): array
    {
        // Index paths predate decoded segments and the current index alias.
        $this->assertSafePath('stored URL', $path);
        $root = $this->rootForRequest($request, $siteDomain);
        $domainPath = rtrim($siteDomain->path ?? '', '/');
        $base = $root . $domainPath;
        if ($path !== '/') {
            $base .= '/' . ltrim($path, '/');
        } elseif ($domainPath === '') {
            $base .= '/pc__index__pc';
        }
        $base .= StatelessPaginationRequest::cacheKeySuffix($request);

        return [$base . '.html', $base . PageCache::ERROR_EXTENSION];
    }

    public function normalizePathFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }

    /** @return list<string>|null */
    private function safeRequestSegments(Request $request): ?array
    {
        $segments = $request->segments();
        $safeSegments = [];
        $relativePathLength = 0;

        foreach ($segments as $segment) {
            if (! is_string($segment)) {
                return null;
            }
            $decodedSegment = $this->fullyDecodedSegment($segment);
            $relativePathLength += strlen($segment) + 1;

            if (strlen($segment) > self::MAX_PATH_SEGMENT_LENGTH
                || $relativePathLength > self::MAX_RELATIVE_PATH_LENGTH
                || str_contains($decodedSegment, '..')
                || $decodedSegment === '.'
                || preg_match('/[\x00-\x1F\x7F\/\\\\]/', $decodedSegment) === 1) {
                return null;
            }
            $safeSegments[] = $segment;
        }

        return $safeSegments;
    }

    private function assertSafeSegment(string $label, ?string $value): void
    {
        $decodedValue = $this->fullyDecodedSegment($value ?? '');

        if ($value === null || $value === '' || preg_match('/[\x00-\x1F\x7F\/\\\\]/', $decodedValue) === 1 || str_contains($decodedValue, '..')) {
            throw new InvalidArgumentException(sprintf('Unsafe %s for cache path.', $label));
        }
    }

    private function assertSafePath(string $label, string $value): void
    {
        $decodedValue = $this->fullyDecodedSegment($value);

        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $decodedValue) === 1) {
            throw new InvalidArgumentException(sprintf('Unsafe %s for cache path.', $label));
        }

        if (str_starts_with($decodedValue, '//')) {
            throw new InvalidArgumentException(sprintf('Unsafe %s for cache path.', $label));
        }

        foreach (explode('/', $decodedValue) as $segment) {
            if ($segment === '.' || $segment === '..' || str_contains($segment, '..')) {
                throw new InvalidArgumentException(sprintf('Unsafe %s for cache path.', $label));
            }
        }
    }

    private function fullyDecodedSegment(string $value): string
    {
        $decodedValue = $value;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $nextValue = rawurldecode($decodedValue);

            if ($nextValue === $decodedValue) {
                return $decodedValue;
            }

            $decodedValue = $nextValue;
        }

        return $decodedValue;
    }
}
