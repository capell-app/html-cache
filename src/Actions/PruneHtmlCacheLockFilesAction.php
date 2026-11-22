<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\HtmlCache\Support\Cache\HtmlCachePublicationGuard;
use Carbon\CarbonImmutable;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * Removes HTML cache lock files nobody has used for the retention period.
 *
 * A render lock is created per URL and a publication lock per page cache root (each
 * release directory is a new root), so without pruning they accumulate forever. The
 * live root's publication lock and render slots are never removed: other processes
 * must keep sharing their inodes. Any other file is removed only while this process
 * holds its lock, so a render that holds it is never disturbed; render locks record
 * their last use in their modification time.
 *
 * @method static int run()
 */
final class PruneHtmlCacheLockFilesAction
{
    use AsFake;
    use AsObject;

    /** Bounds one run; a large backlog clears over several runs. */
    public const int MAX_FILES_PER_RUN = 5000;

    public function handle(): int
    {
        try {
            $livePublicationLock = resolve(HtmlCachePublicationGuard::class)->lockPath();
        } catch (Throwable $throwable) {
            report($throwable);

            return 0;
        }

        $directory = dirname($livePublicationLock);
        $defaultDirectory = realpath(storage_path('framework/cache/html-cache-publication'));
        // A configured lock path may share its directory with unrelated files, so there only
        // the live root's own render locks are candidates.
        $pattern = $defaultDirectory !== false && $defaultDirectory === $directory
            ? $directory . '/*.lock*'
            : $livePublicationLock . '.render-*';
        $cutoff = CarbonImmutable::now()->subDays($this->retentionDays())->getTimestamp();
        $pruned = 0;

        foreach (glob($pattern, GLOB_NOSORT) ?: [] as $path) {
            if ($pruned >= self::MAX_FILES_PER_RUN) {
                break;
            }

            if ($this->isLiveRootLock($path, $livePublicationLock) || ! $this->isLockFileName(basename($path))) {
                continue;
            }

            if ($this->pruneIfUnused($path, $cutoff)) {
                $pruned++;
            }
        }

        return $pruned;
    }

    private function isLiveRootLock(string $path, string $livePublicationLock): bool
    {
        return $path === $livePublicationLock || str_starts_with($path, $livePublicationLock . '.render-slot-');
    }

    private function isLockFileName(string $name): bool
    {
        return preg_match('/^[^\/]+\.lock(\.render-slot-\d+|\.render-[0-9a-f]{64})?$/', $name) === 1;
    }

    private function pruneIfUnused(string $path, int $cutoff): bool
    {
        clearstatcache(true, $path);
        $modifiedAt = @filemtime($path);

        if ($modifiedAt === false || $modifiedAt >= $cutoff || is_link($path) || ! is_file($path)) {
            return false;
        }

        // capell-security-scan: local-stream opens an HTML cache lock file in application storage
        $handle = @fopen($path, 'r+b');

        if ($handle === false) {
            return false;
        }

        try {
            // Held by a render or publication right now: it is in use, keep it.
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                return false;
            }

            clearstatcache(true, $path);
            $stillUnused = ($modifiedAt = @filemtime($path)) !== false && $modifiedAt < $cutoff;

            if (! $stillUnused) {
                return false;
            }

            // An old root's URL counters are only read and written under its publication lock,
            // so they go first, while this process still holds it: a publisher waiting on the
            // lock then reopens a new one and can never pair new counters with a lost withdrawal.
            if (str_ends_with($path, '.lock')) {
                @unlink($path . '.urls');
            }

            return @unlink($path);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function retentionDays(): int
    {
        $configured = config('capell-html-cache.retention.lock_file_days', 7);

        return is_numeric($configured) ? max(1, (int) $configured) : 7;
    }
}
