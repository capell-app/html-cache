<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Cache;

use Capell\Core\Models\SiteDomain;
use InvalidArgumentException;

final class HtmlCachePathResolver
{
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
        if ($value === null || $value === '' || preg_match('/[\x00-\x1F\x7F\/\\\\]/', $value) === 1 || str_contains($value, '..')) {
            throw new InvalidArgumentException(sprintf('Unsafe %s for cache path.', $label));
        }
    }

    private function assertSafePath(string $label, string $value): void
    {
        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $value) === 1) {
            throw new InvalidArgumentException(sprintf('Unsafe %s for cache path.', $label));
        }

        if (str_starts_with($value, '//')) {
            throw new InvalidArgumentException(sprintf('Unsafe %s for cache path.', $label));
        }

        foreach (explode('/', $value) as $segment) {
            if ($segment === '..') {
                throw new InvalidArgumentException(sprintf('Unsafe %s for cache path.', $label));
            }
        }
    }
}
