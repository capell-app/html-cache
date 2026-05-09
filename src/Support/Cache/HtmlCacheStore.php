<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Cache;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use RuntimeException;
use Throwable;

final class HtmlCacheStore
{
    private readonly Filesystem $disk;

    public function __construct(FilesystemManager $storage)
    {
        $this->disk = $storage->disk('page_cache');
    }

    public function exists(string $file): bool
    {
        return $this->disk->exists($file);
    }

    public function lastModified(string $file): ?int
    {
        return $this->exists($file) ? $this->disk->lastModified($file) : null;
    }

    public function path(string $file): ?string
    {
        return $this->exists($file) ? $this->disk->path($file) : null;
    }

    public function delete(string $file): bool
    {
        return $this->disk->delete(str_replace(['../', '..\\'], '', $file));
    }

    public function root(): string
    {
        return $this->disk->path('');
    }

    /** @return array<int, string> */
    public function directories(?string $path = null): array
    {
        try {
            return ($path !== null && $path !== '')
                ? $this->disk->directories($path)
                : $this->disk->directories();
        } catch (Throwable $throwable) {
            throw new RuntimeException(sprintf('HTML cache root missing or unreadable at "%s". Original error: %s', $this->safeRootPath(), $throwable->getMessage()), 0, $throwable);
        }
    }

    /** @return array<int, string> */
    public function allDirectories(string $path): array
    {
        try {
            return $this->disk->allDirectories($path);
        } catch (Throwable $throwable) {
            throw new RuntimeException(sprintf('Unable to list all HTML cache directories under "%s". Original error: %s', $path, $throwable->getMessage()), 0, $throwable);
        }
    }

    /** @return array<int, string> */
    public function files(?string $path = null): array
    {
        try {
            return ($path !== null && $path !== '')
                ? $this->disk->files($path)
                : $this->disk->files();
        } catch (Throwable $throwable) {
            throw new RuntimeException(sprintf('HTML cache root missing or unreadable at "%s". Original error: %s', $this->safeRootPath(), $throwable->getMessage()), 0, $throwable);
        }
    }

    /** @return array<int, string> */
    public function allFiles(string $path): array
    {
        try {
            return $this->disk->allFiles($path);
        } catch (Throwable $throwable) {
            throw new RuntimeException(sprintf('Unable to list all HTML cache files under "%s". Original error: %s', $path, $throwable->getMessage()), 0, $throwable);
        }
    }

    public function deleteDirectory(string $directory): bool
    {
        return $this->disk->deleteDirectory($directory);
    }

    public function deleteAll(): void
    {
        foreach ($this->directories() as $directory) {
            $this->deleteDirectory($directory);
        }

        foreach ($this->files() as $file) {
            $this->delete($file);
        }
    }

    private function safeRootPath(): string
    {
        try {
            return $this->root();
        } catch (Throwable) {
            return 'page_cache disk root (unresolved)';
        }
    }
}
