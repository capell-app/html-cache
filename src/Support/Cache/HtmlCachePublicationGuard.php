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

    public function capture(Request $request): ?string
    {
        if ($request->attributes->has(self::REQUEST_TOKEN_ATTRIBUTE)) {
            return $this->token($request);
        }

        $token = $this->snapshot();
        $request->attributes->set(self::REQUEST_TOKEN_ATTRIBUTE, $token);

        return $token;
    }

    public function token(Request $request): ?string
    {
        $token = $request->attributes->get(self::REQUEST_TOKEN_ATTRIBUTE);

        return is_string($token) ? $token : null;
    }

    public function snapshot(): ?string
    {
        try {
            return $this->locked(fn ($handle): string => $this->generation($handle));
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
                if (! hash_equals($this->generation($handle), $token)) {
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

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to acquire the HTML cache publication lock.');
            }

            return $operation($handle);
        } finally {
            flock($handle, LOCK_UN);
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

    /** @param resource $handle */
    private function rotate(mixed $handle): string
    {
        $token = bin2hex(random_bytes(32));
        rewind($handle);

        if (! ftruncate($handle, 0) || fwrite($handle, $token) !== strlen($token) || ! fflush($handle)) {
            throw new RuntimeException('Unable to advance the HTML cache publication generation.');
        }

        return $token;
    }
}
