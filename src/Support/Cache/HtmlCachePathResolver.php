<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Cache;

use Capell\Core\Models\SiteDomain;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class HtmlCachePathResolver
{
    public function pathForRequestUrl(string $url, SiteDomain $siteDomain, bool $error = false): string
    {
        $request = Request::create($url);
        $path = $this->normalizePathFromUrl($url);
        $domainPath = rtrim($siteDomain->path ?? '', '/');

        if ($domainPath !== '') {
            if ($path === $domainPath) {
                $path = '/';
            } elseif (str_starts_with($path, $domainPath . '/')) {
                $path = substr($path, strlen($domainPath));
            }
        }

        $suffix = StatelessPaginationRequest::cacheKeySuffix($request);

        if ($suffix === '') {
            return $this->pathForUrl($path, $siteDomain, $error);
        }

        $lastSlash = strrpos($path, '/');
        $directory = $lastSlash === false ? '' : substr($path, 0, $lastSlash + 1);
        $filename = $lastSlash === false ? $path : substr($path, $lastSlash + 1);
        $variantPath = $directory . ($filename === '' ? 'pc__index__pc' : $filename) . $suffix;

        return $this->pathForUrl($variantPath, $siteDomain, $error);
    }

    public function pathForUrl(string $url, SiteDomain $siteDomain, bool $error = false): string
    {
        $this->assertSafeSegment('scheme', $siteDomain->scheme);
        $this->assertSafeSegment('domain', $siteDomain->domain);
        $this->assertSafePath('site domain path', $siteDomain->path ?? '/');
        $this->assertSafePath('URL', $url);

        $path = sprintf('%s.%s', $siteDomain->scheme, $siteDomain->domain);

        if ($siteDomain->path !== null && $siteDomain->path !== '') {
            $path .= $siteDomain->path;
        }

        if ($url === '/') {
            if ($siteDomain->path !== null && $siteDomain->path !== '') {
                return $path . ($error ? '.404' : '') . '.html';
            }

            $cacheName = 'pc__index__pc';
        } else {
            $cacheName = ltrim($url, '/');
        }

        if ($error) {
            $cacheName .= '.404';
        }

        return $path . '/' . $cacheName . '.html';
    }

    public function normalizePathFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
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
