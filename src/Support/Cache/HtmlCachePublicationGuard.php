<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Cache;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Rendering happens outside the lock. Only publication and hard invalidation
 * share it, so a render started before withdrawal cannot restore deleted HTML.
 * The lock lives outside the page directory and has no expiring lease.
 */
final class HtmlCachePublicationGuard
{
    public const string REQUEST_TOKEN_ATTRIBUTE = 'capell.html_cache.publication_token';

    public const string REJECTED_ATTRIBUTE = 'capell.html_cache.publication_rejected';

    /** @var array<string, true> */
    private static array $heldLocks = [];

    /** The withdrawal scope for a URL: every query variant of one host and path. */
    public static function urlKey(Request $request): string
    {
        return hash('sha256', mb_strtolower($request->getHost()) . '/' . trim($request->path(), '/'));
    }

    public function capture(Request $request): ?string
    {
        if ($request->attributes->has(self::REQUEST_TOKEN_ATTRIBUTE)) {
            return $this->token($request);
        }

        $token = $this->snapshot(self::urlKey($request));
        $request->attributes->set(self::REQUEST_TOKEN_ATTRIBUTE, $token);

        return $token;
    }

    public function token(Request $request): ?string
    {
        $token = $request->attributes->get(self::REQUEST_TOKEN_ATTRIBUTE);

        return is_string($token) ? $token : null;
    }

    /**
     * A token binds the site-wide generation and, when a URL is given, that
     * URL's withdrawal counter, so a no-op withdrawal can reject the renders of
     * its own URL without rejecting every other publish.
     */
    public function snapshot(?string $urlKey = null): ?string
    {
        try {
            return $this->locked(function ($handle) use ($urlKey): string {
                $generation = $this->generation($handle);

                return $urlKey === null
                    ? $generation
                    : $generation . '|' . $urlKey . '|' . ($this->urlCounters()[$urlKey] ?? 0);
            });
        } catch (Throwable $throwable) {
            report($throwable);

            return null;
        }
    }

    /** @param Closure(): bool $publish */
    public function publish(?string $token, Closure $publish): bool
    {
        if ($token === null) {
            return false;
        }

        try {
            return $this->locked(function ($handle) use ($token, $publish): bool {
                [$generation, $urlKey, $counter] = array_pad(explode('|', $token, 3), 3, null);

                if (! hash_equals($this->generation($handle), (string) $generation)) {
                    return false;
                }

                if ($urlKey !== null && (string) ($this->urlCounters()[$urlKey] ?? 0) !== $counter) {
                    return false;
                }

                return $publish();
            });
        } catch (Throwable $throwable) {
            report($throwable);

            return false;
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $invalidate
     * @return T
     */
    public function invalidate(Closure $invalidate): mixed
    {
        return $this->locked(function ($handle) use ($invalidate): mixed {
            $this->rotate($handle);

            return $invalidate();
        });
    }

    /**
     * Withdraw one URL's artefacts under the lock, advancing the site-wide
     * generation only when something was actually removed.
     *
     * The generation exists so a render that started before a withdrawal cannot
     * republish what the withdrawal removed. An empty disk does not prove no
     * render of this URL is in flight: one may have captured its token and not
     * yet written. So a no-op withdrawal still advances this URL's counter,
     * rejecting its in-flight renders, without rotating the site-wide
     * generation, which would reject every concurrent publish. Per-request
     * retirements of non-HTML and redirect responses starved stale refreshes
     * exactly that way.
     *
     * Data changes keep using invalidate(): a render that read the old data may
     * be running for any URL. A null key withdraws without fencing anything
     * when nothing was removed; use it only for cleanup that follows this
     * caller's own guarded publish of the same URL.
     *
     * @param  Closure(): bool  $withdraw
     */
    public function withdraw(?string $urlKey, Closure $withdraw): bool
    {
        return $this->locked(function ($handle) use ($urlKey, $withdraw): bool {
            try {
                $removed = $withdraw();
            } catch (Throwable $throwable) {
                // A partial deletion is unknowable; stay conservative.
                $this->rotate($handle);

                throw $throwable;
            }

            if ($removed) {
                $this->rotate($handle);
            } elseif ($urlKey !== null) {
                $counters = $this->urlCounters();
                $counters[$urlKey] = ($counters[$urlKey] ?? 0) + 1;
                $this->writeUrlCounters($counters);
            }

            return $removed;
        });
    }

    public function lockPath(): string
    {
        $root = Storage::disk('page_cache')->path('');
        File::ensureDirectoryExists($root, 0775, true);
        $root = realpath($root);

        if ($root === false) {
            throw new RuntimeException('Unable to resolve the HTML cache directory.');
        }

        $configuredPath = config('capell-html-cache.deployment.publication_lock_path');

        if (config('capell-html-cache.deployment.shared_page_cache', false) === true
            && (! is_string($configuredPath) || $configuredPath === '')) {
            throw new RuntimeException('A shared HTML cache requires a shared publication_lock_path outside the page cache directory.');
        }

        $path = is_string($configuredPath) && $configuredPath !== ''
            ? $configuredPath
            : storage_path('framework/cache/html-cache-publication/' . hash('sha256', $root) . '.lock');

        File::ensureDirectoryExists(dirname($path), 0775, true);
        $parent = realpath(dirname($path));
        $existingPath = realpath($path);

        if ($parent === false || ($existingPath === false && is_link($path))) {
            throw new RuntimeException('Unable to resolve the HTML cache publication lock.');
        }

        $path = $existingPath !== false ? $existingPath : $parent . '/' . basename($path);

        if (str_starts_with(rtrim($path, '/'), rtrim($root, '/') . '/') || $path === rtrim($root, '/')) {
            throw new RuntimeException('The HTML cache publication lock must be outside the page cache directory.');
        }

        return $path;
    }

    /**
     * @template T
     *
     * @param  Closure(resource): T  $operation
     * @return T
     */
    private function locked(Closure $operation): mixed
    {
        $handle = fopen($this->lockPath(), 'c+b');

        if ($handle === false) {
            throw new RuntimeException('Unable to open the HTML cache publication lock.');
        }

        $acquired = false;
        $identity = null;
        try {
            $metadata = fstat($handle);
            if ($metadata === false) {
                throw new RuntimeException('Unable to identify the HTML cache publication lock.');
            }
            $identity = $metadata['dev'] . ':' . $metadata['ino'];
            // A second handle to our own locked inode blocks forever. Refuse
            // nested publication/invalidation, including across guard instances.
            if (isset(self::$heldLocks[$identity])) {
                throw new RuntimeException('Cannot reacquire the HTML cache publication lock within the same operation.');
            }
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to acquire the HTML cache publication lock.');
            }
            $acquired = true;
            self::$heldLocks[$identity] = true;

            return $operation($handle);
        } finally {
            if ($acquired && $identity !== null) {
                unset(self::$heldLocks[$identity]);
                flock($handle, LOCK_UN);
            }
            fclose($handle);
        }
    }

    /** @param resource $handle */
    private function generation(mixed $handle): string
    {
        rewind($handle);
        $token = stream_get_contents($handle);

        if ($token === '') {
            return $this->rotate($handle);
        }

        if (! is_string($token) || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            throw new RuntimeException('The HTML cache publication generation is unreadable.');
        }

        return $token;
    }

    /** @return array<string, int> */
    private function urlCounters(): array
    {
        $path = $this->lockPath() . '.urls';
        $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];

        return is_array($decoded) ? array_filter($decoded, is_int(...)) : [];
    }

    /** @param array<string, int> $counters */
    private function writeUrlCounters(array $counters): void
    {
        $path = $this->lockPath() . '.urls';

        if ($counters === []) {
            if (is_file($path) && ! unlink($path)) {
                throw new RuntimeException('Unable to reset the HTML cache URL withdrawal counters.');
            }

            return;
        }

        // Replace atomically: a torn file would read as empty and re-validate old tokens.
        $temporary = $path . '.' . bin2hex(random_bytes(4));
        if (file_put_contents($temporary, json_encode($counters, JSON_THROW_ON_ERROR)) === false || ! rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException('Unable to record the HTML cache URL withdrawal counters.');
        }
    }

    /** @param resource $handle */
    private function rotate(mixed $handle): string
    {
        // A new generation already rejects every older token, so per-URL
        // counters restart; this keeps the counter file bounded.
        $this->writeUrlCounters([]);
        $token = bin2hex(random_bytes(32));
        rewind($handle);

        if (! ftruncate($handle, 0) || fwrite($handle, $token) !== strlen($token) || ! fflush($handle)) {
            throw new RuntimeException('Unable to advance the HTML cache publication generation.');
        }

        return $token;
    }
}
