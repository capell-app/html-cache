<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Cache;

use Closure;
use RuntimeException;
use Throwable;

/**
 * Lock files are pruned once unused, so a handle opened just before the prune removed its
 * file can be granted a lock on an inode nobody else can reach any more, while the next
 * process creates a new file and locks that one. A holder therefore confirms, after
 * locking, that its handle is still the file at the path; when it is not, it reopens.
 */
final class HtmlCacheLockFile
{
    /**
     * Each lost race needs another prune between this process's open and lock, so a few
     * reopens settle any real race. A path that never matches its handle (a filesystem
     * whose identities disagree, or something deleting the file continuously) would
     * otherwise hold a web request or worker in this loop forever.
     */
    public const int MAX_OPENS = 5;

    /**
     * Opens and locks the file at $path until the locked handle is the file at the path.
     *
     * @param  Closure(resource): void|null  $beforeLock  Runs on each new handle before it is locked; it may throw.
     * @return resource|null Null when $blocking is false and another process holds the lock.
     */
    public static function lock(string $path, bool $blocking, string $description, ?Closure $beforeLock = null): mixed
    {
        for ($open = 1; $open <= self::MAX_OPENS; $open++) {
            // capell-security-scan: local-stream reads an HTML cache lock file in application storage
            $handle = fopen($path, 'c+b');

            if ($handle === false) {
                throw new RuntimeException(sprintf('Unable to open the %s.', $description));
            }

            if ($beforeLock instanceof Closure) {
                try {
                    $beforeLock($handle);
                } catch (Throwable $throwable) {
                    fclose($handle);

                    throw $throwable;
                }
            }

            if (! flock($handle, $blocking ? LOCK_EX : LOCK_EX | LOCK_NB, $wouldBlock)) {
                fclose($handle);

                if (! $blocking && $wouldBlock === 1) {
                    return null;
                }

                throw new RuntimeException(sprintf('Unable to acquire the %s.', $description));
            }

            if (self::isCurrent($handle, $path)) {
                return $handle;
            }

            flock($handle, LOCK_UN);
            fclose($handle);
        }

        throw new RuntimeException(sprintf('The %s kept changing while it was being locked.', $description));
    }

    /** @param resource $handle */
    public static function isCurrent(mixed $handle, string $path): bool
    {
        $opened = fstat($handle);
        clearstatcache(true, $path);
        $current = @stat($path);

        return $opened !== false
            && $current !== false
            && $opened['dev'] === $current['dev']
            && $opened['ino'] === $current['ino'];
    }
}
