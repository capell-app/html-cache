<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Cache;

use Capell\HtmlCache\Data\HtmlCacheClearResult;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\File;
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

    public function put(string $file, string $contents): void
    {
        $this->disk->put(str_replace(['../', '..\\'], '', $file), $contents);
    }

    public function replace(string $file, string $contents): void
    {
        $safeFile = str_replace(['../', '..\\'], '', $file);
        $path = $this->disk->path($safeFile);

        File::ensureDirectoryExists(dirname($path), 0775, true);
        File::replace($path, $contents);
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

    public function deleteAll(): HtmlCacheClearResult
    {
        $deletedDirectories = [];
        $deletedFiles = [];
        $failedDirectories = [];
        $failedFiles = [];

        foreach ($this->directories() as $directory) {
            try {
                if ($this->deleteDirectory($directory)) {
                    $deletedDirectories[] = $directory;
                } else {
                    $failedDirectories[] = $directory;
                }
            } catch (Throwable $throwable) {
                $failedDirectories[] = sprintf('%s (%s)', $directory, $throwable->getMessage());
            }
        }

        foreach ($this->files() as $file) {
            try {
                if ($this->delete($file)) {
                    $deletedFiles[] = $file;
                } else {
                    $failedFiles[] = $file;
                }
            } catch (Throwable $throwable) {
                $failedFiles[] = sprintf('%s (%s)', $file, $throwable->getMessage());
            }
        }

        return new HtmlCacheClearResult(
            deletedDirectories: $deletedDirectories,
            deletedFiles: $deletedFiles,
            failedDirectories: $failedDirectories,
            failedFiles: $failedFiles,
        );
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
