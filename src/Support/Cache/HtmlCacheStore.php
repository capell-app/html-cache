<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Cache;

use Capell\HtmlCache\Data\HtmlCacheClearResult;
use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\Filesystem as NativeFilesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\File;
use League\Flysystem\PathTraversalDetected;
use RuntimeException;
use Throwable;

final class HtmlCacheStore
{
    private readonly Filesystem $disk;

    private ?NativeFilesystem $pageFilesystem = null;

    private ?string $pageDirectory = null;

    private ?string $resolvedRoot = null;

    public function __construct(FilesystemManager $storage)
    {
        $this->disk = $storage->disk('page_cache');
    }

    public function usingPageFilesystem(NativeFilesystem $files, string $directory): self
    {
        // PageCache supports a caller-selected directory and filesystem. Keep that
        // ownership when routing CLI invalidation through the common deletion path.
        $store = clone $this;
        $store->pageFilesystem = $files;
        $store->pageDirectory = rtrim($directory, '/');

        return $store;
    }

    public function exists(string $file): bool
    {
        try {
            return $this->disk->exists($file);
        } catch (PathTraversalDetected) {
            return false;
        }
    }

    public function lastModified(string $file): ?int
    {
        return $this->exists($file) ? $this->disk->lastModified($file) : null;
    }

    public function path(string $file): ?string
    {
        return $this->exists($file) ? $this->diskPath($file) : null;
    }

    public function delete(string $file): bool
    {
        $file = str_replace(['../', '..\\'], '', $file);
        HtmlCacheFilesystem::assertContainedPath($this->diskPath($file), $this->root());

        return resolve(HtmlCachePublicationGuard::class)->invalidate(
            fn (): bool => $this->disk->delete($file),
        );
    }

    public function deletePage(string $file, ?string $domainDirectory = null): bool
    {
        return resolve(HtmlCachePublicationGuard::class)->invalidate(fn (): bool => $this->deletePageFiles($file, $domainDirectory));
    }

    /**
     * @param  list<string>  $files
     * @param  list<string>  $variantBases
     * @param  list<string>  $unattributableLegacyBases
     * @param  list<string>  $recordedFiles
     * @param  list<string>  $preserve  pages just published under this lock scope, kept with their sidecars
     * @param  bool  $rotateWhenUnchanged  false for retirements that only observe origin policy; see HtmlCachePublicationGuard::withdraw()
     * @param  string|null  $fenceUrlKey  when not rotating, the URL whose in-flight renders a no-op withdrawal rejects
     * @param  Closure(Closure(): bool): bool|null  $guard  runs the deletion inside the publication lock so the caller can add its own precondition (a claim lock) in the same order as the publish path; it must call the closure it is given to delete
     */
    public function deletePagesInDomain(array $files, array $variantBases, string $domainDirectory, array $unattributableLegacyBases = [], array $recordedFiles = [], array $preserve = [], bool $rotateWhenUnchanged = true, ?string $fenceUrlKey = null, ?Closure $guard = null): bool
    {
        $publication = resolve(HtmlCachePublicationGuard::class);
        $delete = function () use ($files, $variantBases, $domainDirectory, $unattributableLegacyBases, $recordedFiles, $preserve): bool {
            // Independently keyed fragments retain exact query ownership.
            foreach (array_unique($variantBases) as $base) {
                if (! $this->isSafePagePath($base, $domainDirectory)) {
                    continue;
                }

                $stem = preg_replace('/\.html$/', '', $base);
                if (! is_string($stem)) {
                    continue;
                }

                $pattern = '/^' . preg_quote($stem, '/') . '(?:~f[a-f0-9]{16})?(?:\.404)?\.(?:html|json|xml)(?:' . preg_quote(PageCache::FRAGMENT_METADATA_EXTENSION, '/') . ')?$/';
                foreach ($this->pageFilesInDomain(dirname($base), $domainDirectory) as $file) {
                    if (preg_match($pattern, $file) === 1) {
                        $files[] = $file;
                    }
                }
            }

            $recordedStems = [];
            foreach ($recordedFiles as $file) {
                if ($this->isSafePagePath($file, $domainDirectory)) {
                    $recordedStems[preg_replace('/(?:\.404)?\.(?:html|json|xml)$/', '', $file) ?? $file] = true;
                }
            }

            foreach (array_unique($unattributableLegacyBases) as $base) {
                if (! $this->isSafePagePath($base, $domainDirectory)) {
                    continue;
                }

                $stem = preg_replace('/\.html$/', '', $base);
                if (! is_string($stem)) {
                    continue;
                }

                // Conservative eviction exception: legacy fragments and untracked
                // full query responses share opaque ~<16-hex> names. Evict only
                // unattributable artefacts on this page path in this domain;
                // regeneration is safer than retaining stale bytes. The required
                // hash excludes the canonical no-query entry from this sweep.
                $pattern = '/^(' . preg_quote($stem, '/') . '~[a-f0-9]{16})(?:\.404)?\.html(?:' . preg_quote(PageCache::FRAGMENT_METADATA_EXTENSION, '/') . ')?$/';
                foreach ($this->pageFilesInDomain(dirname($base), $domainDirectory) as $file) {
                    if (preg_match($pattern, $file, $matches) === 1 && ! isset($recordedStems[$matches[1]])) {
                        $files[] = $file;
                    }
                }
            }

            $kept = [];
            foreach ($preserve as $file) {
                $kept[$file] = true;
                $kept[$file . PageCache::FRAGMENT_METADATA_EXTENSION] = true;
            }

            $deleted = false;
            foreach (array_unique($files) as $file) {
                if (isset($kept[$file])) {
                    continue;
                }

                $deleted = $this->deletePageFiles($file, $domainDirectory) || $deleted;
            }

            return $deleted;
        };

        $operation = $guard instanceof Closure ? static fn (): bool => $guard($delete) : $delete;

        return $rotateWhenUnchanged ? $publication->invalidate($operation) : $publication->withdraw($fenceUrlKey, $operation);
    }

    public function isSafePagePath(string $file, string $domainDirectory): bool
    {
        if (! str_starts_with($file, $domainDirectory . '/') || str_contains($file, '\\')) {
            return false;
        }

        $absolutePath = $this->pageDirectory ?? rtrim($this->root(), '/');
        if (is_link($absolutePath)) {
            return false;
        }

        if ($this->pageDirectory !== null && str_starts_with($absolutePath, rtrim($this->root(), '/') . '/')) {
            try {
                HtmlCacheFilesystem::assertContainedPath($absolutePath, $this->root());
            } catch (RuntimeException) {
                return false;
            }
        }

        $segments = explode('/', $this->pageDirectory === null ? $file : substr($file, strlen($domainDirectory) + 1));
        foreach ($segments as $segment) {
            $decoded = $segment;
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $decoded = rawurldecode($decoded);
            }

            // Encoded separators in historical filenames are literal bytes on disk;
            // Flysystem normalises actual separators but does not URL-decode paths.
            if ($segment === '' || $decoded === '.' || str_contains($decoded, '..')
                || preg_match('/[\x00-\x1F\x7F]/', $decoded) === 1) {
                return false;
            }

            $absolutePath .= '/' . $segment;
            // Flysystem's lexical normalisation does not protect against symlinked ancestors.
            if (is_link($absolutePath)) {
                return false;
            }
        }

        return true;
    }

    public function put(string $file, string $contents): void
    {
        $file = str_replace(['../', '..\\'], '', $file);
        HtmlCacheFilesystem::assertContainedPath($this->diskPath($file), $this->root());
        $this->disk->put($file, $contents);
    }

    public function replace(string $file, string $contents): void
    {
        $safeFile = str_replace(['../', '..\\'], '', $file);
        $path = $this->diskPath($safeFile);
        HtmlCacheFilesystem::assertContainedPath($path, $this->root());

        File::ensureDirectoryExists(dirname($path), 0775, true);
        File::replace($path, $contents);
    }

    public function root(): string
    {
        // The configured disk may be an alias. Trust that root once, then inspect
        // every descendant beneath its real location with the usual link guards.
        return $this->resolvedRoot ??= HtmlCacheFilesystem::resolvedPath($this->disk->path(''));
    }

    /** @return list<string> */
    public function portDirectories(string $domainDirectory): array
    {
        $domainDirectory = preg_replace('/^~port-\d+\//', '', $domainDirectory) ?? $domainDirectory;
        if (! HtmlCacheFilesystem::directoryExists($this->root())) {
            return [];
        }

        $directories = [];
        foreach (File::directories($this->root()) as $portDirectory) {
            $prefix = basename($portDirectory);
            if (preg_match('/^~port-([1-9]\d*)$/', $prefix, $matches) !== 1
                || (int) $matches[1] > 65535 || in_array((int) $matches[1], [80, 443], true)) {
                continue;
            }

            $directory = $prefix . '/' . $domainDirectory;
            if ($this->isSafePagePath($directory . '/scope.html', $directory)
                && HtmlCacheFilesystem::directoryExists($this->diskPath($directory))) {
                $directories[] = $directory;
            }
        }

        return $directories;
    }

    /** @return array<int, string> */
    public function directories(?string $path = null): array
    {
        try {
            if (! $this->directoryExists($path)) {
                return [];
            }

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
            if (! $this->directoryExists($path)) {
                return [];
            }

            return $this->disk->allDirectories($path);
        } catch (Throwable $throwable) {
            throw new RuntimeException(sprintf('Unable to list all HTML cache directories under "%s". Original error: %s', $path, $throwable->getMessage()), 0, $throwable);
        }
    }

    /** @return array<int, string> */
    public function files(?string $path = null): array
    {
        try {
            if (! $this->directoryExists($path)) {
                return [];
            }

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
            if (! $this->directoryExists($path)) {
                return [];
            }

            return $this->disk->allFiles($path);
        } catch (Throwable $throwable) {
            throw new RuntimeException(sprintf('Unable to list all HTML cache files under "%s". Original error: %s', $path, $throwable->getMessage()), 0, $throwable);
        }
    }

    public function deleteDirectory(string $directory): bool
    {
        HtmlCacheFilesystem::assertContainedPath($this->diskPath($directory), $this->root());

        return resolve(HtmlCachePublicationGuard::class)->invalidate(fn (): bool => $this->disk->deleteDirectory($directory));
    }

    public function deleteAll(): HtmlCacheClearResult
    {
        return resolve(HtmlCachePublicationGuard::class)->invalidate($this->deleteAllFiles(...));
    }

    private function deletePageFiles(string $file, ?string $domainDirectory): bool
    {
        if ($domainDirectory !== null && ! $this->isSafePagePath($file, $domainDirectory)) {
            return false;
        }

        $safeFile = str_replace(['../', '..\\'], '', $file);
        $files = [$safeFile];

        if (str_ends_with($safeFile, '.html')) {
            $files[] = $safeFile . PageCache::FRAGMENT_METADATA_EXTENSION;
        }

        $deleted = false;

        foreach ($files as $artifact) {
            if ($domainDirectory !== null && ! $this->isSafePagePath($artifact, $domainDirectory)) {
                continue;
            }

            $nativePath = $domainDirectory === null ? null : $this->pagePath($artifact, $domainDirectory);
            if ($domainDirectory === null) {
                HtmlCacheFilesystem::assertContainedPath($this->diskPath($artifact), $this->root());
            }

            HtmlCacheFilesystem::directoryExists(dirname($nativePath ?? $this->diskPath($artifact)));

            if ($nativePath !== null && $this->pageFilesystem instanceof NativeFilesystem) {
                if (! $this->pageFilesystem->exists($nativePath)) {
                    continue;
                }

                $removed = $this->pageFilesystem->delete($nativePath);
            } elseif ($this->disk->exists($artifact)) {
                $removed = $this->disk->delete($artifact);
            } else {
                continue;
            }

            if (! $removed) {
                throw new RuntimeException(sprintf('Unable to delete HTML cache artefact "%s".', $artifact));
            }

            $deleted = true;
        }

        return $deleted;
    }

    /** @return list<string> */
    private function pageFilesInDomain(string $directory, string $domainDirectory): array
    {
        $absoluteDirectory = $this->pagePath($directory, $domainDirectory) ?? $this->diskPath($directory);
        if (! $this->isSafePagePath($directory . '/scope.html', $domainDirectory)
            || ! HtmlCacheFilesystem::directoryExists($absoluteDirectory)) {
            return [];
        }

        // A shallow native listing does not traverse links; Flysystem rejects the
        // whole listing when even an unrelated symlink exists in the directory.
        $files = [];
        foreach (File::files($absoluteDirectory, hidden: true) as $file) {
            $relativePath = $directory . '/' . $file->getFilename();
            if ($this->isSafePagePath($relativePath, $domainDirectory)) {
                $files[] = $relativePath;
            }
        }

        return $files;
    }

    private function pagePath(string $file, string $domainDirectory): ?string
    {
        return $this->pageDirectory === null ? null : $this->pageDirectory . substr($file, strlen($domainDirectory));
    }

    private function deleteAllFiles(): HtmlCacheClearResult
    {
        $deletedDirectories = [];
        $deletedFiles = [];
        $failedDirectories = [];
        $failedFiles = [];

        foreach ($this->directories() as $directory) {
            try {
                HtmlCacheFilesystem::assertContainedPath($this->diskPath($directory), $this->root());
                if ($this->disk->deleteDirectory($directory)) {
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
                HtmlCacheFilesystem::assertContainedPath($this->diskPath($file), $this->root());
                if ($this->disk->delete($file)) {
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

    private function directoryExists(?string $path): bool
    {
        return HtmlCacheFilesystem::directoryExists($this->diskPath($path ?? ''));
    }

    private function diskPath(string $file): string
    {
        $configuredRoot = rtrim($this->disk->path(''), '/');

        return $this->root() . substr($this->disk->path($file), strlen($configuredRoot));
    }
}
