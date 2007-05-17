<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Cache;

use RuntimeException;

final class HtmlCacheFilesystem
{
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
}
