<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Health;

use Capell\Core\Contracts\Extensions\ChecksExtensionHealth;
use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Frontend\Support\Routing\FrontendRouteMiddlewareRegistry;
use Capell\HtmlCache\Console\Commands\ProcessStaleHtmlCacheCommand;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class HtmlCacheHealthCheck implements ChecksExtensionHealth
{
    private const string PAGE_CACHE_DISK = 'page_cache';

    private const string FRONTEND_CACHE_MIDDLEWARE_ALIAS = 'frontend.cache';

    private const string STALE_PROCESSING_COMMAND = 'capell:html-cache:process-stale';

    /**
     * @var list<string>
     */
    private const array REQUIRED_TABLES = [
        'cached_model_urls',
        'stale_cached_urls',
        'html_cache_generation_runs',
    ];

    public static function compatibleCapellApiVersion(): string
    {
        return '^1.0';
    }

    /**
     * @return Collection<int, DoctorCheckResultData>
     */
    public static function runDiagnostics(): Collection
    {
        $check = new self;

        return collect([
            $check->pageCacheDiskWritableCheck(),
            $check->pageCacheDiskLocalPathCheck(),
            $check->multiNodePurgeSafetyCheck(),
            $check->frontendCacheMiddlewareWiredCheck(),
            $check->storageTablesCheck(),
            $check->staleProcessingCommandRegisteredCheck(),
        ]);
    }

    public static function passed(): bool
    {
        return self::runDiagnostics()
            ->every(static fn (DoctorCheckResultData $result): bool => $result->passed);
    }

    public function pageCacheDiskLocalPathCheck(): DoctorCheckResultData
    {
        $supportsLocalPaths = $this->pageCacheDiskSupportsLocalPaths();

        return new DoctorCheckResultData(
            label: (string) __('capell-html-cache::health.local_path.label'),
            passed: $supportsLocalPaths,
            message: $supportsLocalPaths
                ? (string) __('capell-html-cache::health.local_path.passed')
                : (string) __('capell-html-cache::health.local_path.failed'),
            remediation: $supportsLocalPaths
                ? null
                : (string) __('capell-html-cache::health.local_path.remediation'),
        );
    }

    /**
     * Asserts the page cache disk can be written to and read back.
     */
    public function pageCacheDiskWritableCheck(): DoctorCheckResultData
    {
        $writable = $this->isPageCacheDiskWritable();

        return new DoctorCheckResultData(
            label: (string) __('capell-html-cache::health.disk.label'),
            passed: $writable,
            message: $writable
                ? (string) __('capell-html-cache::health.disk.passed')
                : (string) __('capell-html-cache::health.disk.failed'),
            remediation: $writable
                ? null
                : (string) __('capell-html-cache::health.disk.remediation'),
        );
    }

    /**
     * Warns when node-local cache files cannot be invalidated across every web node.
     */
    public function multiNodePurgeSafetyCheck(): DoctorCheckResultData
    {
        $webNodeCount = $this->configuredWebNodeCount();
        $pageCacheDriver = $this->pageCacheDiskDriver();
        $purgeDriver = $this->configuredPurgeDriver();
        $usesMultipleWebNodes = $webNodeCount > 1;
        $usesLocalPageCacheDisk = $pageCacheDriver === 'local';
        $usesSharedPageCacheDisk = config('capell-html-cache.deployment.shared_page_cache', false) === true;
        $hasEdgePurgeDriver = in_array($purgeDriver, ['cloudflare', 'http'], true);
        $passed = ! $usesMultipleWebNodes
            || ! $usesLocalPageCacheDisk
            || $usesSharedPageCacheDisk
            || $hasEdgePurgeDriver;

        return new DoctorCheckResultData(
            label: (string) __('capell-html-cache::health.multi_node_purge.label'),
            passed: $passed,
            message: $this->multiNodePurgeMessage(
                webNodeCount: $webNodeCount,
                pageCacheDriver: $pageCacheDriver,
                purgeDriver: $purgeDriver,
                usesSharedPageCacheDisk: $usesSharedPageCacheDisk,
                hasEdgePurgeDriver: $hasEdgePurgeDriver,
            ),
            remediation: $passed
                ? null
                : (string) __('capell-html-cache::health.multi_node_purge.remediation'),
        );
    }

    /**
     * Asserts the frontend.cache middleware alias is registered on the router.
     */
    public function frontendCacheMiddlewareWiredCheck(): DoctorCheckResultData
    {
        $wired = $this->isFrontendCacheMiddlewareWired();

        return new DoctorCheckResultData(
            label: (string) __('capell-html-cache::health.middleware.label'),
            passed: $wired,
            message: $wired
                ? (string) __('capell-html-cache::health.middleware.passed')
                : (string) __('capell-html-cache::health.middleware.failed'),
            remediation: $wired
                ? null
                : (string) __('capell-html-cache::health.middleware.remediation'),
        );
    }

    /**
     * Asserts the dependency-index and stale-queue tables exist.
     */
    public function storageTablesCheck(): DoctorCheckResultData
    {
        $missingTables = $this->missingTables();

        return new DoctorCheckResultData(
            label: (string) __('capell-html-cache::health.tables.label'),
            passed: $missingTables === [],
            message: $missingTables === []
                ? (string) __('capell-html-cache::health.tables.passed')
                : (string) __('capell-html-cache::health.tables.failed', ['tables' => implode(', ', $missingTables)]),
            remediation: $missingTables === []
                ? null
                : (string) __('capell-html-cache::health.tables.remediation'),
        );
    }

    /**
     * Asserts the scheduled-invalidation command is registered when scheduled mode is active.
     */
    public function staleProcessingCommandRegisteredCheck(): DoctorCheckResultData
    {
        $usesScheduledInvalidation = $this->usesScheduledInvalidation();
        $commandRegistered = $this->isStaleProcessingCommandRegistered();
        $passed = ! $usesScheduledInvalidation || $commandRegistered;

        return new DoctorCheckResultData(
            label: (string) __('capell-html-cache::health.stale_command.label'),
            passed: $passed,
            message: $this->staleProcessingCommandMessage($usesScheduledInvalidation, $commandRegistered),
            remediation: $passed
                ? null
                : (string) __('capell-html-cache::health.stale_command.remediation', ['command' => self::STALE_PROCESSING_COMMAND]),
        );
    }

    /**
     * @return list<string>
     */
    public function missingTables(): array
    {
        try {
            return array_values(collect(self::REQUIRED_TABLES)
                ->reject(static fn (string $tableName): bool => Schema::hasTable($tableName))
                ->values()
                ->all());
        } catch (Throwable) {
            return self::REQUIRED_TABLES;
        }
    }

    public function isPageCacheDiskWritable(): bool
    {
        $disk = null;
        $probeFile = null;

        try {
            $disk = Storage::disk(self::PAGE_CACHE_DISK);
            $probeFile = '.html-cache-health-' . bin2hex(random_bytes(8)) . '.tmp';

            if (! $disk->put($probeFile, 'ok')) {
                return false;
            }

            return $disk->get($probeFile) === 'ok';
        } catch (Throwable) {
            return false;
        } finally {
            if ($disk !== null && $probeFile !== null) {
                try {
                    $disk->delete($probeFile);
                } catch (Throwable) {
                    // Cleanup is best-effort; the probe result above is the diagnostic signal.
                }
            }
        }
    }

    public function pageCacheDiskSupportsLocalPaths(): bool
    {
        try {
            $root = Storage::disk(self::PAGE_CACHE_DISK)->path('');

            return $root !== '' && str_starts_with($root, DIRECTORY_SEPARATOR);
        } catch (Throwable) {
            return false;
        }
    }

    public function isFrontendCacheMiddlewareWired(): bool
    {
        return $this->hasFrontendCacheMiddlewareAlias()
            && $this->frontendCacheMiddlewareIsInFrontendStack();
    }

    public function hasFrontendCacheMiddlewareAlias(): bool
    {
        $aliases = Route::getMiddleware();

        return ($aliases[self::FRONTEND_CACHE_MIDDLEWARE_ALIAS] ?? null) === HtmlCacheMiddleware::class;
    }

    public function frontendCacheMiddlewareIsInFrontendStack(): bool
    {
        try {
            $middleware = resolve(FrontendRouteMiddlewareRegistry::class)->all();
        } catch (Throwable) {
            return false;
        }

        return in_array(self::FRONTEND_CACHE_MIDDLEWARE_ALIAS, $middleware, true);
    }

    public function isStaleProcessingCommandRegistered(): bool
    {
        try {
            $commands = resolve(ConsoleKernel::class)->all();
        } catch (Throwable) {
            return false;
        }

        if (array_key_exists(self::STALE_PROCESSING_COMMAND, $commands)) {
            return true;
        }

        return collect($commands)
            ->contains(static fn (object $command): bool => $command instanceof ProcessStaleHtmlCacheCommand);
    }

    public function usesScheduledInvalidation(): bool
    {
        return config('capell-html-cache.invalidation.mode', 'instant') === 'scheduled';
    }

    private function configuredWebNodeCount(): int
    {
        $configuredWebNodeCount = config('capell-html-cache.deployment.web_node_count', 1);

        return is_numeric($configuredWebNodeCount) ? max(1, (int) $configuredWebNodeCount) : 1;
    }

    private function pageCacheDiskDriver(): string
    {
        $driver = config('filesystems.disks.' . self::PAGE_CACHE_DISK . '.driver');

        return is_string($driver) && $driver !== '' ? strtolower($driver) : 'unconfigured';
    }

    private function configuredPurgeDriver(): string
    {
        $driver = config('capell-html-cache.purge.driver', 'null');

        return is_string($driver) && $driver !== '' ? strtolower($driver) : 'null';
    }

    private function multiNodePurgeMessage(
        int $webNodeCount,
        string $pageCacheDriver,
        string $purgeDriver,
        bool $usesSharedPageCacheDisk,
        bool $hasEdgePurgeDriver,
    ): string {
        if ($webNodeCount === 1) {
            return (string) __('capell-html-cache::health.multi_node_purge.single_node');
        }

        if ($pageCacheDriver !== 'local') {
            return (string) __('capell-html-cache::health.multi_node_purge.non_local_disk', [
                'nodes' => $webNodeCount,
                'driver' => $pageCacheDriver,
            ]);
        }

        if ($usesSharedPageCacheDisk) {
            return (string) __('capell-html-cache::health.multi_node_purge.shared_disk', [
                'nodes' => $webNodeCount,
            ]);
        }

        if ($hasEdgePurgeDriver) {
            return (string) __('capell-html-cache::health.multi_node_purge.edge_driver', [
                'nodes' => $webNodeCount,
                'driver' => $purgeDriver,
            ]);
        }

        return (string) __('capell-html-cache::health.multi_node_purge.failed', [
            'nodes' => $webNodeCount,
            'driver' => $purgeDriver,
        ]);
    }

    private function staleProcessingCommandMessage(bool $usesScheduledInvalidation, bool $commandRegistered): string
    {
        if (! $usesScheduledInvalidation) {
            return (string) __('capell-html-cache::health.stale_command.not_required');
        }

        return $commandRegistered
            ? (string) __('capell-html-cache::health.stale_command.passed', ['command' => self::STALE_PROCESSING_COMMAND])
            : (string) __('capell-html-cache::health.stale_command.failed', ['command' => self::STALE_PROCESSING_COMMAND]);
    }
}
