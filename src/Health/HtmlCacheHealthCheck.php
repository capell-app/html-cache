<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Health;

use Capell\Core\Contracts\Extensions\ChecksExtensionHealth;
use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
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
    ];

    public static function compatibleCapellApiVersion(): string
    {
        return '^4.0';
    }

    /**
     * @return Collection<int, DoctorCheckResultData>
     */
    public static function runDiagnostics(): Collection
    {
        $check = new self;

        return collect([
            $check->pageCacheDiskWritableCheck(),
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

    /**
     * Asserts the page cache disk can be written to and read back.
     */
    public function pageCacheDiskWritableCheck(): DoctorCheckResultData
    {
        $writable = $this->isPageCacheDiskWritable();

        return new DoctorCheckResultData(
            label: 'HTML cache disk writable',
            passed: $writable,
            message: $writable
                ? 'The page_cache disk is configured and writable for static HTML output.'
                : 'The page_cache disk could not be written to; cached HTML cannot be stored.',
            remediation: $writable
                ? null
                : 'Ensure the page_cache filesystem disk is configured and its root directory is writable by the web server.',
        );
    }

    /**
     * Asserts the frontend.cache middleware alias is registered on the router.
     */
    public function frontendCacheMiddlewareWiredCheck(): DoctorCheckResultData
    {
        $wired = $this->isFrontendCacheMiddlewareWired();

        return new DoctorCheckResultData(
            label: 'Frontend HTML cache middleware wired',
            passed: $wired,
            message: $wired
                ? 'The frontend.cache middleware alias resolves to the HTML cache middleware.'
                : 'The frontend.cache middleware alias is not wired to the HTML cache middleware; public pages will not be cached.',
            remediation: $wired
                ? null
                : 'Ensure HtmlCacheServiceProvider registers the frontend.cache middleware alias on the frontend route stack.',
        );
    }

    /**
     * Asserts the dependency-index and stale-queue tables exist.
     */
    public function storageTablesCheck(): DoctorCheckResultData
    {
        $missingTables = $this->missingTables();

        return new DoctorCheckResultData(
            label: 'HTML cache storage tables',
            passed: $missingTables === [],
            message: $missingTables === []
                ? 'The cached_model_urls dependency index and stale_cached_urls queue tables are present.'
                : 'Missing tables: ' . implode(', ', $missingTables) . '.',
            remediation: $missingTables === []
                ? null
                : 'Run the Capell migrations to create the HTML cache storage tables.',
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
            label: 'Scheduled stale-regeneration command registered',
            passed: $passed,
            message: $this->staleProcessingCommandMessage($usesScheduledInvalidation, $commandRegistered),
            remediation: $passed
                ? null
                : 'Ensure HtmlCacheServiceProvider registers the ' . self::STALE_PROCESSING_COMMAND . ' command while invalidation mode is scheduled.',
        );
    }

    /**
     * @return list<string>
     */
    public function missingTables(): array
    {
        return array_values(collect(self::REQUIRED_TABLES)
            ->reject(static fn (string $tableName): bool => Schema::hasTable($tableName))
            ->values()
            ->all());
    }

    public function isPageCacheDiskWritable(): bool
    {
        try {
            $disk = Storage::disk(self::PAGE_CACHE_DISK);
            $probeFile = '.html-cache-health-' . bin2hex(random_bytes(8)) . '.tmp';

            if (! $disk->put($probeFile, 'ok')) {
                return false;
            }

            $written = $disk->get($probeFile) === 'ok';
            $disk->delete($probeFile);

            return $written;
        } catch (Throwable) {
            return false;
        }
    }

    public function isFrontendCacheMiddlewareWired(): bool
    {
        $aliases = Route::getMiddleware();

        return ($aliases[self::FRONTEND_CACHE_MIDDLEWARE_ALIAS] ?? null) === HtmlCacheMiddleware::class;
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

    private function staleProcessingCommandMessage(bool $usesScheduledInvalidation, bool $commandRegistered): string
    {
        if (! $usesScheduledInvalidation) {
            return 'Invalidation mode is instant; scheduled stale-regeneration is not required.';
        }

        return $commandRegistered
            ? 'Scheduled invalidation is active and the ' . self::STALE_PROCESSING_COMMAND . ' command is registered.'
            : 'Scheduled invalidation is active but the ' . self::STALE_PROCESSING_COMMAND . ' command is not registered.';
    }
}
