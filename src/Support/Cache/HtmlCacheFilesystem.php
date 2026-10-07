<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Cache;

use RuntimeException;

final class HtmlCacheFilesystem
{
    public static function assertContainedPath(string $path, string $root): void
    {
        clearstatcache(true);
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $path = str_replace('\\', '/', $path);
        if (! self::isWithin($path, $root) || in_array('..', explode('/', $path), true) || str_contains($path, "\0")) {
            throw new RuntimeException('HTML cache path is outside the cache root.');
        }

        // Resolve the deepest existing ancestor before creating directories.
        // Lexical containment alone follows pre-existing links outside the disk.
        $resolvedRoot = self::resolvedPath($root);
        $parent = rtrim($path, '/') === $root ? $path : dirname($path);
        if (! self::isWithin(self::resolvedPath($parent), $resolvedRoot)
            || ! self::isWithin(self::resolvedPath($path), $resolvedRoot)) {
            throw new RuntimeException('HTML cache path is outside the cache root.');
        }
    }

    public static function directoryExists(string $path): bool
    {
        clearstatcache(true, $path);

        if (is_dir($path)) {
            if (! is_readable($path) || ! is_executable($path)) {
                throw new RuntimeException(sprintf('Unable to inspect HTML cache directory "%s".', $path));
            }

            return true;
        }

        $parent = dirname($path);

        // A false stat only proves absence after its ancestors can be inspected.
        if ($parent === $path || file_exists($path) || is_link($path)) {
            throw new RuntimeException(sprintf('Unable to inspect HTML cache directory "%s".', $path));
        }

        self::directoryExists($parent);

        return false;
    }

    public static function resolvedPath(string $path): string
    {
        $suffix = [];
        while (! file_exists($path) && ! is_link($path)) {
            $parent = dirname($path);
            if ($parent === $path) {
                throw new RuntimeException('Unable to resolve the HTML cache path.');
            }

            array_unshift($suffix, basename($path));
            $path = $parent;
        }

        $resolved = realpath($path);
        if ($resolved === false) {
            throw new RuntimeException('Unable to resolve the HTML cache path.');
        }

        return rtrim(str_replace('\\', '/', $resolved), '/') . ($suffix === [] ? '' : '/' . implode('/', $suffix));
    }

    private static function isWithin(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, $root . '/');
    }
}
