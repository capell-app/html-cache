<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Cache;

use Capell\Frontend\Actions\AssertPublicHtmlContainsNoAuthoringSurfaceAction;
use Capell\Frontend\Contracts\CacheBypassResolver;
use Capell\Frontend\Contracts\HtmlMinifier;
use Capell\Frontend\Data\RenderHookFragmentCacheData;
use Capell\Frontend\Support\Security\PublicHtmlSafetyInspector;
use Capell\HtmlCache\Enums\HtmlCacheEligibilityReason;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Models\StaleCachedUrl;
use Illuminate\Contracts\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

final class PageCache
{
    public const string ERROR_EXTENSION = '.404.html';

    public const string ERROR_PAGE = '404-error.html';

    public const string FRAGMENT_METADATA_EXTENSION = '.fragments.json';

    private const int MAX_PATH_SEGMENT_LENGTH = 255;

    private const int MAX_RELATIVE_PATH_LENGTH = 2048;

    private ?Container $container = null;

    private ?string $cachePath = null;

    public function __construct(
        private readonly Filesystem $files,
    ) {}

    public function setContainer(Container $container): self
    {
        $this->container = $container;

        return $this;
    }

    public function setCachePath(string $path): self
    {
        $this->cachePath = rtrim($path, '\/');

        return $this;
    }

    public function getCachePath(?string ...$paths): string
    {
        $base = $this->cachePath ?? $this->getDefaultCachePath();

        throw_if($base === null, RuntimeException::class, 'HTML cache path not set.');

        $segments = array_values(array_filter(
            $paths,
            static fn (?string $path): bool => $path !== null && $path !== '',
        ));

        return $this->join([$base, ...$segments]);
    }

    public function cache(SymfonyRequest $request, SymfonyResponse $response): bool
    {
        /** @var Request $laravelRequest */
        $laravelRequest = $request;

        /** @var Response $laravelResponse */
        $laravelResponse = $response;

        if (! in_array($laravelResponse->getStatusCode(), [
            SymfonyResponse::HTTP_OK,
            SymfonyResponse::HTTP_NOT_FOUND,
        ], true)) {
            return false;
        }

        if (resolve(ConfiguredHtmlCacheBypassRules::class)->shouldBypass($laravelRequest)) {
            return false;
        }

        $cacheLocation = $this->getDirectoryAndFileNames($laravelRequest, $laravelResponse);

        if ($cacheLocation === null) {
            return false;
        }

        [$path, $filename, $extension] = $cacheLocation;
        $content = (string) $response->getContent();
        $fragmentCache = $extension === 'html'
            ? $laravelRequest->attributes->get(HtmlCacheMiddleware::FRAGMENT_CACHE_DATA_ATTRIBUTE)
            : null;
        $fragmentCache = $fragmentCache instanceof RenderHookFragmentCacheData ? $fragmentCache : null;

        if ($fragmentCache instanceof RenderHookFragmentCacheData) {
            $content = $fragmentCache->shell;
        }

        if ($extension === 'html' && $this->containsUnsafeSharedHtml($laravelRequest, $content)) {
            return false;
        }

        $publication = resolve(HtmlCachePublicationGuard::class);
        $token = $publication->token($laravelRequest);

        if ($response->getStatusCode() !== SymfonyResponse::HTTP_NOT_FOUND && $extension === 'html' && $fragmentCache === null && config('capell-html-cache.minify_html', true) === true) {
            $content = resolve(HtmlMinifier::class)->minify($content);
        }

        $published = $publication->publish($token, function () use ($laravelRequest, $response, $path, $filename, $extension, $content, $fragmentCache): bool {
            if ($response->getStatusCode() === SymfonyResponse::HTTP_NOT_FOUND) {
                $errorPath = $this->join([$path, $filename . self::ERROR_EXTENSION]);

                if (! $this->reserveErrorPageSlot($errorPath)) {
                    return false;
                }

                $this->files->makeDirectory($path, 0775, true, true);
                if (! $this->writeCacheFile(
                    $laravelRequest,
                    $errorPath,
                    $content,
                )) {
                    return false;
                }

                $fragmentMetadataPath = $errorPath . self::FRAGMENT_METADATA_EXTENSION;

                if ($fragmentCache instanceof RenderHookFragmentCacheData) {
                    $metadata = json_encode($fragmentCache->metadata(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

                    if (! $this->writeCacheFile($laravelRequest, $fragmentMetadataPath, $metadata)) {
                        throw new RuntimeException('Unable to publish render hook fragment metadata for the cached 404 page.');
                    }

                    return true;
                }

                $this->files->delete($fragmentMetadataPath);

                return true;
            }

            $this->files->makeDirectory($path, 0775, true, true);

            $targetPath = $this->join([$path, $filename . '.' . $extension]);

            if (! $this->reserveVariantSlot($targetPath, StatelessPaginationRequest::cacheKeySuffix($laravelRequest))) {
                return false;
            }

            if (! $this->writeCacheFile(
                $laravelRequest,
                $targetPath,
                $content,
            )) {
                return false;
            }

            $fragmentMetadataPath = $targetPath . self::FRAGMENT_METADATA_EXTENSION;

            if ($fragmentCache instanceof RenderHookFragmentCacheData) {
                $metadata = json_encode($fragmentCache->metadata(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

                if (! $this->writeCacheFile($laravelRequest, $fragmentMetadataPath, $metadata)) {
                    throw new RuntimeException('Unable to publish render hook fragment metadata for the cached page.');
                }

                return true;
            }

            $this->files->delete($fragmentMetadataPath);

            return true;
        });

        if (! $published) {
            $laravelRequest->attributes->set(HtmlCachePublicationGuard::REJECTED_ATTRIBUTE, true);
        }

        return $published;
    }

    public function getCachePage(Request $request): bool|string
    {
        $path = $this->getFileFromRequest($request);

        if ($path === null) {
            return false;
        }

        return $this->readCacheFile($path);
    }

    public function getCacheErrorPage(Request $request): bool|string
    {
        $path = $this->getFileFromRequest($request, self::ERROR_EXTENSION);

        if ($path === null) {
            return false;
        }

        return $this->readCacheFile($path);
    }

    public function getCacheFragmentData(Request $request, string $extension = '.html'): ?RenderHookFragmentCacheData
    {
        $path = $this->getFileFromRequest($request, $extension);

        if ($path === null) {
            return null;
        }

        $shell = $this->readCacheFile($path);
        $metadataPath = $path . self::FRAGMENT_METADATA_EXTENSION;

        if (! is_string($shell)) {
            $this->files->delete($metadataPath);

            return null;
        }

        if (! $this->files->exists($metadataPath)) {
            return null;
        }

        try {
            return RenderHookFragmentCacheData::fromMetadata($shell, $this->decodeFragmentMetadata($metadataPath));
        } catch (Throwable $throwable) {
            report($throwable);
            $this->files->delete($metadataPath);

            return null;
        }
    }

    public function shouldCachePage(Request $request, SymfonyResponse $response): bool
    {
        return ! $this->rejectionReason($request, $response) instanceof HtmlCacheEligibilityReason;
    }

    /** Return the actual first blocker so diagnostics cannot invent a response directive. */
    public function rejectionReason(Request $request, SymfonyResponse $response): ?HtmlCacheEligibilityReason
    {
        if (resolve(CacheBypassResolver::class)->shouldBypass()) {
            return HtmlCacheEligibilityReason::CacheBypassResolver;
        }

        if (config('capell-html-cache.enabled', true) !== true) {
            return HtmlCacheEligibilityReason::CacheDisabled;
        }

        if ($request->has('without_html_cache')) {
            return HtmlCacheEligibilityReason::ExplicitCacheBypass;
        }

        if ($request->query->count() > 0 && ! StatelessPaginationRequest::isCacheableVariant($request)) {
            return HtmlCacheEligibilityReason::QueryStringPresent;
        }

        if (resolve(ConfiguredHtmlCacheBypassRules::class)->shouldBypass($request)) {
            return HtmlCacheEligibilityReason::ConfiguredBypassRule;
        }

        if ($this->isInertiaRequest($request)) {
            return HtmlCacheEligibilityReason::InertiaRequest;
        }

        if (! $request->isMethod('GET')) {
            return HtmlCacheEligibilityReason::NonGetRequest;
        }

        if ($this->safeRequestSegments($request) === null) {
            return HtmlCacheEligibilityReason::UnsafeRequestPath;
        }

        if ($this->sessionHasUserState($request)) {
            return HtmlCacheEligibilityReason::SessionUserState;
        }

        $responseReason = resolve(PublicResponseCachePolicy::class)->reasons($response)[0] ?? null;

        if ($responseReason instanceof HtmlCacheEligibilityReason) {
            return $responseReason;
        }

        if (! in_array($response->getStatusCode(), [200, 404], true)) {
            return HtmlCacheEligibilityReason::UncacheableResponseStatus;
        }

        if (! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            return HtmlCacheEligibilityReason::NonHtmlResponse;
        }

        $fragmentCache = $request->attributes->get(HtmlCacheMiddleware::FRAGMENT_CACHE_DATA_ATTRIBUTE);
        $content = $fragmentCache instanceof RenderHookFragmentCacheData
            ? $fragmentCache->shell
            : (string) $response->getContent();
        $htmlReason = $this->unsafeSharedHtmlReason($request, $content);

        if ($htmlReason instanceof HtmlCacheEligibilityReason) {
            return $htmlReason;
        }

        return $request->headers->has('x-livewire') ? HtmlCacheEligibilityReason::LivewireRequest : null;
    }

    public function forget(string $slug): bool
    {
        return resolve(HtmlCachePublicationGuard::class)->invalidate(function () use ($slug): bool {
            $deleted = false;

            foreach (['html', 'json', 'xml'] as $extension) {
                $deleted = $this->files->delete($this->getCachePath($slug . '.' . $extension)) || $deleted;

                if ($extension === 'html') {
                    $deleted = $this->files->delete($this->getCachePath($slug . '.html' . self::FRAGMENT_METADATA_EXTENSION)) || $deleted;
                }
            }

            if ($this->files->delete($this->getCachePath($slug . self::ERROR_EXTENSION))) {
                $deleted = true;
            }

            $deleted = $this->files->delete($this->getCachePath($slug . self::ERROR_EXTENSION . self::FRAGMENT_METADATA_EXTENSION)) || $deleted;

            return $deleted;
        });
    }

    public function clear(?string $path = null): bool
    {
        return resolve(HtmlCachePublicationGuard::class)->invalidate(fn (): bool => $this->files->deleteDirectory($this->getCachePath($path), preserve: true));
    }

    /** @return array<string, mixed> */
    private function decodeFragmentMetadata(string $path): array
    {
        $decoded = json_decode($this->files->get($path), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('Fragment metadata root must be an object.');
        }

        $metadata = [];

        foreach ($decoded as $key => $value) {
            if (! is_string($key)) {
                throw new RuntimeException('Fragment metadata object keys must be strings.');
            }

            $metadata[$key] = $value;
        }

        return $metadata;
    }

    private function aliasFilename(?string $filename): string
    {
        if (in_array($filename, [null, '', 'index'], true)) {
            return 'pc__index__pc';
        }

        return $filename;
    }

    private function readCacheFile(string $path): bool|string
    {
        if (! $this->files->exists($path)) {
            return false;
        }

        $configuredTimeToLive = config(
            'capell-html-cache.filesystem_ttl_seconds',
            config('capell-html-cache.cache_ttl', 3600),
        );
        $timeToLive = is_numeric($configuredTimeToLive) ? max(0, (int) $configuredTimeToLive) : 3600;

        if ($timeToLive > 0 && $this->files->lastModified($path) <= now()->subSeconds($timeToLive)->timestamp) {
            $this->files->delete($path);

            if (str_ends_with($path, '.html')) {
                $this->files->delete($path . self::FRAGMENT_METADATA_EXTENSION);
            }

            return false;
        }

        try {
            return $this->files->get($path);
        } catch (Throwable $throwable) {
            clearstatcache(true, $path);

            if (! is_file($path)) {
                return false;
            }

            throw $throwable;
        }
    }

    private function reserveErrorPageSlot(string $targetPath): bool
    {
        if ($this->files->exists($targetPath)) {
            return true;
        }

        $configuredMaximum = config('capell-html-cache.error_pages.max_files_per_host', 500);
        $maximum = is_numeric($configuredMaximum) ? max(0, (int) $configuredMaximum) : 500;

        if ($maximum === 0) {
            return false;
        }

        $root = $this->getCachePath();

        if (! $this->files->isDirectory($root)) {
            return true;
        }

        $errorPages = array_values(array_filter(
            $this->files->allFiles($root),
            static fn (SplFileInfo $file): bool => str_ends_with($file->getFilename(), self::ERROR_EXTENSION),
        ));

        if (count($errorPages) < $maximum) {
            return true;
        }

        usort(
            $errorPages,
            static fn (SplFileInfo $first, SplFileInfo $second): int => $first->getMTime() <=> $second->getMTime(),
        );

        $configuredRetained = config('capell-html-cache.error_pages.retain_after_prune', 450);
        $retained = is_numeric($configuredRetained) ? max(0, (int) $configuredRetained) : 450;
        $retained = min($retained, max(0, $maximum - 1));

        $deleteCount = max(1, count($errorPages) - $retained);
        $deleted = 0;

        foreach (array_slice($errorPages, 0, $deleteCount) as $errorPage) {
            if ($this->files->delete($errorPage->getPathname())) {
                $deleted++;
            }
        }

        return count($errorPages) - $deleted < $maximum;
    }

    private function reserveVariantSlot(string $targetPath, string $suffix): bool
    {
        if ($suffix === '' || $this->files->exists($targetPath)) {
            return true;
        }

        $configuredMaximum = config('capell-html-cache.stateless_pagination.max_variants_per_path', 100);
        $maximum = is_numeric($configuredMaximum) ? max(0, (int) $configuredMaximum) : 100;

        if ($maximum === 0) {
            return false;
        }

        $directory = dirname($targetPath);
        $filename = basename($targetPath);
        $markerPosition = strpos($filename, '~');

        if ($markerPosition === false) {
            return true;
        }

        $prefix = substr($filename, 0, $markerPosition) . '~';
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $variants = array_values(array_filter(
            $this->files->files($directory),
            static fn (SplFileInfo $file): bool => str_starts_with($file->getFilename(), $prefix)
                && $file->getExtension() === $extension,
        ));

        if (count($variants) < $maximum) {
            return true;
        }

        usort($variants, static fn (SplFileInfo $first, SplFileInfo $second): int => $first->getMTime() <=> $second->getMTime());

        $deleted = $this->files->delete($variants[0]->getPathname());

        if ($deleted && $extension === 'html') {
            $this->files->delete($variants[0]->getPathname() . self::FRAGMENT_METADATA_EXTENSION);
        }

        return $deleted;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    /** @return array{string, string, string}|null */
    private function getDirectoryAndFileNames(SymfonyRequest $request, SymfonyResponse $response): ?array
    {
        /** @var Request $laravelRequest */
        $laravelRequest = $request;
        /** @var Response $laravelResponse */
        $laravelResponse = $response;

        $segments = $this->safeRequestSegments($laravelRequest);

        if ($segments === null) {
            return null;
        }

        $filename = $this->aliasFilename(array_pop($segments)) . StatelessPaginationRequest::cacheKeySuffix($laravelRequest);
        $extension = $this->guessFileExtension($laravelResponse);

        return [$this->getCachePath(implode('/', $segments)), $filename, $extension];
    }

    private function guessFileExtension(SymfonyResponse $response): string
    {
        $contentType = $response->headers->get('Content-Type');

        if ($response instanceof JsonResponse || $contentType === 'application/json') {
            return 'json';
        }

        if (in_array($contentType, ['text/xml', 'application/xml'], true)) {
            return 'xml';
        }

        return 'html';
    }

    /**
     * @param  list<string>  $paths
     */
    private function join(array $paths): string
    {
        $trimmed = array_map(
            static fn (string $path): string => trim($path, '/'),
            $paths,
        );

        $target = implode('/', array_filter($trimmed, static fn (string $path): bool => $path !== ''));
        $source = $paths[0] ?? '';

        return str_starts_with($source, '/') ? '/' . $target : $target;
    }

    private function getDefaultCachePath(): ?string
    {
        if ($this->container?->bound('path.public') === true) {
            $publicPath = $this->container->make('path.public');

            return is_string($publicPath) ? $publicPath . '/page-cache' : null;
        }

        return null;
    }

    private function getFileFromRequest(Request $request, string $extension = '.html'): ?string
    {
        $segments = $this->safeRequestSegments($request);

        if ($segments === null) {
            return null;
        }

        $filename = $this->aliasFilename(array_pop($segments)) . StatelessPaginationRequest::cacheKeySuffix($request);

        return $this->getCachePath(implode(DIRECTORY_SEPARATOR, $segments)) . DIRECTORY_SEPARATOR . $filename . $extension;
    }

    /** @return array<int, string>|null */
    private function safeRequestSegments(Request $request): ?array
    {
        $segments = $request->segments();
        $relativePathLength = 0;

        foreach ($segments as $segment) {
            $segment = (string) $segment;
            $decodedSegment = $this->fullyDecodedPathSegment($segment);
            $relativePathLength += strlen($segment) + 1;

            if ($decodedSegment === '.'
                || $decodedSegment === '..'
                || strlen($segment) > self::MAX_PATH_SEGMENT_LENGTH
                || $relativePathLength > self::MAX_RELATIVE_PATH_LENGTH
                || str_contains($decodedSegment, '..')
                || preg_match('/[\x00-\x1F\x7F\/\\\\]/', $decodedSegment) === 1) {
                return null;
            }
        }

        return $segments;
    }

    private function fullyDecodedPathSegment(string $segment): string
    {
        $decodedSegment = $segment;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $nextSegment = rawurldecode($decodedSegment);

            if ($nextSegment === $decodedSegment) {
                return $decodedSegment;
            }

            $decodedSegment = $nextSegment;
        }

        return $decodedSegment;
    }

    private function isInertiaRequest(Request $request): bool
    {
        if ($request->headers->has('X-Inertia')) {
            return true;
        }

        if ($request->headers->has('X-Inertia-Version')) {
            return true;
        }

        if ($request->headers->has('X-Inertia-Partial-Component')) {
            return true;
        }

        if ($request->headers->has('X-Inertia-Partial-Data')) {
            return true;
        }

        return $request->headers->has('X-Inertia-Reset');
    }

    private function sessionHasUserState(Request $request): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        $session = $request->session();
        if (filled($session->get('_flash.old', []))) {
            return true;
        }

        if (filled($session->get('_flash.new', []))) {
            return true;
        }

        if ($session->has('errors')) {
            return true;
        }

        if ($session->has('_old_input')) {
            return true;
        }

        if ($session->has('status')) {
            return true;
        }

        if ($session->has('enquiry_status')) {
            return true;
        }

        return $session->has('roadmap-status');
    }

    private function containsUnsafeSharedHtml(Request $request, string $content): bool
    {
        return $this->unsafeSharedHtmlReason($request, $content) instanceof HtmlCacheEligibilityReason;
    }

    private function unsafeSharedHtmlReason(Request $request, string $content): ?HtmlCacheEligibilityReason
    {
        try {
            $inspector = resolve(PublicHtmlSafetyInspector::class);

            if (! $this->hasMatchingSafeInspection($request, $content)
                && $inspector->containsAuthoringSurface($content)) {
                return HtmlCacheEligibilityReason::UnsafePublicOutput;
            }

            if (! method_exists($inspector, 'containsBakedCsrfToken')) {
                return HtmlCacheEligibilityReason::BakedSessionTokenInspectorUnavailable;
            }

            return $inspector->containsBakedCsrfToken($content) ? HtmlCacheEligibilityReason::BakedSessionToken : null;
        } catch (Throwable) {
            return HtmlCacheEligibilityReason::PublicHtmlInspectionFailed;
        }
    }

    private function hasMatchingSafeInspection(Request $request, string $content): bool
    {
        return $request->attributes->get(AssertPublicHtmlContainsNoAuthoringSurfaceAction::SAFE_INSPECTION_PASSED_ATTRIBUTE) === true
            && $request->attributes->get(AssertPublicHtmlContainsNoAuthoringSurfaceAction::SAFE_INSPECTION_HASH_ATTRIBUTE) === hash('xxh128', $content);
    }

    private function writeCacheFile(Request $request, string $path, string $content): bool
    {
        $staleCachedUrlId = $request->attributes->get(HtmlCacheMiddleware::STALE_CACHE_ID_ATTRIBUTE);
        $claimToken = $request->attributes->get(HtmlCacheMiddleware::STALE_CACHE_CLAIM_TOKEN_ATTRIBUTE);

        if ($staleCachedUrlId === null && $claimToken === null) {
            $this->files->replace($path, $content);

            return true;
        }

        return $this->replaceCacheFileForCurrentStaleClaim($staleCachedUrlId, $claimToken, $path, $content);
    }

    private function replaceCacheFileForCurrentStaleClaim(mixed $staleCachedUrlId, mixed $claimToken, string $path, string $content): bool
    {
        if (! is_numeric($staleCachedUrlId) || ! is_string($claimToken) || $claimToken === '') {
            return false;
        }

        $temporaryPath = $this->temporaryPathForAtomicReplace($path);
        $bytesWritten = $this->files->put($temporaryPath, $content);

        if ($bytesWritten === false || $bytesWritten !== strlen($content)) {
            throw new RuntimeException(sprintf('Unable to write temporary cache file for "%s".', $path));
        }

        $replaced = false;

        try {
            DB::transaction(function () use ($staleCachedUrlId, $claimToken, $path, $temporaryPath, &$replaced): void {
                $staleCachedUrl = StaleCachedUrl::query()
                    ->whereKey((int) $staleCachedUrlId)
                    ->lockForUpdate()
                    ->first();

                if (
                    ! $staleCachedUrl instanceof StaleCachedUrl
                    || $staleCachedUrl->status !== StaleCachedUrl::STATUS_PROCESSING
                    || $staleCachedUrl->claim_token !== $claimToken
                ) {
                    return;
                }

                if (! $this->files->move($temporaryPath, $path)) {
                    throw new RuntimeException(sprintf('Unable to replace cache file for "%s".', $path));
                }

                $replaced = true;
            });
        } finally {
            if ($this->files->exists($temporaryPath)) {
                $this->files->delete($temporaryPath);
            }
        }

        return $replaced;
    }

    private function temporaryPathForAtomicReplace(string $path): string
    {
        return dirname($path) . DIRECTORY_SEPARATOR . basename($path) . '.tmp.' . Str::uuid()->toString();
    }
}
