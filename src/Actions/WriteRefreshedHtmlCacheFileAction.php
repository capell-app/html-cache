<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Frontend\Contracts\HtmlMinifier;
use Capell\HtmlCache\Models\StaleCachedUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * @method static void run(Response $response, StaleCachedUrl $staleCachedUrl)
 */
final class WriteRefreshedHtmlCacheFileAction
{
    use AsFake;
    use AsObject;

    public function handle(Response $response, StaleCachedUrl $staleCachedUrl): void
    {
        $cachePath = $response->getStatusCode() === Response::HTTP_NOT_FOUND
            ? $staleCachedUrl->error_cache_path
            : $staleCachedUrl->cache_path;

        if (! is_string($cachePath) || $cachePath === '') {
            throw new RuntimeException(sprintf('Unable to refresh stale HTML cache for "%s"; stale row cache path was missing.', $staleCachedUrl->url));
        }

        $content = (string) $response->getContent();

        if ($response->getStatusCode() !== Response::HTTP_NOT_FOUND && config('capell-html-cache.minify_html', true) === true) {
            $content = resolve(HtmlMinifier::class)->minify($content);
        }

        $safeCachePath = $this->safeCachePath($cachePath);
        $disk = Storage::disk('page_cache');
        $path = $disk->path($safeCachePath);
        $root = rtrim(str_replace('\\', '/', $disk->path('')), '/');
        $normalizedPath = str_replace('\\', '/', $path);

        if ($normalizedPath !== $root && ! str_starts_with($normalizedPath, $root . '/')) {
            throw new RuntimeException(sprintf('Unable to refresh stale HTML cache for "%s"; stale row cache path was outside the cache disk.', $staleCachedUrl->url));
        }

        File::ensureDirectoryExists(dirname($path), 0775, true);
        $this->replaceCacheFileForCurrentStaleClaim($staleCachedUrl, $path, $content);
    }

    private function safeCachePath(string $cachePath): string
    {
        $normalized = str_replace('\\', '/', $cachePath);

        throw_if($normalized === '' || str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:\//', $normalized) === 1 || str_contains($normalized, "\0"), RuntimeException::class, 'Unable to refresh stale HTML cache; stale row cache path was invalid.');

        $segments = array_values(array_filter(explode('/', $normalized), static fn (string $segment): bool => $segment !== ''));

        foreach ($segments as $segment) {
            throw_if($segment === '..', RuntimeException::class, 'Unable to refresh stale HTML cache; stale row cache path was invalid.');
        }

        return implode('/', $segments);
    }

    private function replaceCacheFileForCurrentStaleClaim(StaleCachedUrl $staleCachedUrl, string $path, string $content): void
    {
        $claimToken = $staleCachedUrl->claim_token;

        if (! is_string($claimToken) || $claimToken === '') {
            throw new RuntimeException(sprintf('Unable to refresh stale HTML cache for "%s"; stale row claim was missing.', $staleCachedUrl->url));
        }

        $temporaryPath = $this->temporaryPathForAtomicReplace($path);
        $bytesWritten = File::put($temporaryPath, $content);

        if ($bytesWritten === false || $bytesWritten !== strlen($content)) {
            throw new RuntimeException(sprintf('Unable to write temporary cache file for "%s".', $path));
        }

        try {
            DB::transaction(function () use ($staleCachedUrl, $claimToken, $path, $temporaryPath): void {
                $currentStaleCachedUrl = StaleCachedUrl::query()
                    ->whereKey($staleCachedUrl->getKey())
                    ->lockForUpdate()
                    ->first();

                if (
                    ! $currentStaleCachedUrl instanceof StaleCachedUrl
                    || $currentStaleCachedUrl->status !== StaleCachedUrl::STATUS_PROCESSING
                    || $currentStaleCachedUrl->claim_token !== $claimToken
                ) {
                    return;
                }

                if (! File::move($temporaryPath, $path)) {
                    throw new RuntimeException(sprintf('Unable to replace cache file for "%s".', $path));
                }
            });
        } finally {
            if (File::exists($temporaryPath)) {
                File::delete($temporaryPath);
            }
        }
    }

    private function temporaryPathForAtomicReplace(string $path): string
    {
        return dirname($path) . DIRECTORY_SEPARATOR . basename($path) . '.tmp.' . Str::uuid()->toString();
    }
}
