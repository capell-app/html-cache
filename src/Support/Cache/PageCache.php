<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Cache;

use Capell\Frontend\Actions\AssertPublicHtmlContainsNoAuthoringSurfaceAction;
use Capell\Frontend\Contracts\CacheBypassResolver;
use Capell\Frontend\Contracts\HtmlMinifier;
use Capell\Frontend\Support\Security\PublicHtmlSafetyInspector;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Models\StaleCachedUrl;
use Illuminate\Contracts\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class PageCache
{
    public const string ERROR_EXTENSION = '.404.html';

    public const string ERROR_PAGE = '404-error.html';

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

    public function cache(SymfonyRequest $request, SymfonyResponse $response): void
    {
        /** @var Request $laravelRequest */
        $laravelRequest = $request;

        /** @var Response $laravelResponse */
        $laravelResponse = $response;

        if (resolve(ConfiguredHtmlCacheBypassRules::class)->shouldBypass($laravelRequest)) {
            return;
        }

        $cacheLocation = $this->getDirectoryAndFileNames($laravelRequest, $laravelResponse);

        if ($cacheLocation === null) {
            return;
        }

        [$path, $filename, $extension] = $cacheLocation;
        $content = (string) $response->getContent();

        if ($extension === 'html' && $this->containsAuthoringSurface($laravelRequest, $content)) {
            return;
        }

        $this->files->makeDirectory($path, 0775, true, true);

        if ($response->getStatusCode() === SymfonyResponse::HTTP_NOT_FOUND) {
            $this->writeCacheFile(
                $laravelRequest,
                $this->join([$path, $filename . self::ERROR_EXTENSION]),
                (string) $laravelResponse->getContent(),
            );

            return;
        }

        if ($extension === 'html' && config('capell-html-cache.minify_html', true) === true) {
            $content = resolve(HtmlMinifier::class)->minify($content);
        }

        $this->writeCacheFile(
            $laravelRequest,
            $this->join([$path, $filename . '.' . $extension]),
            $content,
        );
    }

    public function getCachePage(Request $request): bool|string
    {
        $path = $this->getFileFromRequest($request);

        if ($path === null) {
            return false;
        }

        return File::exists($path) ? File::get($path) : false;
    }

    public function getCacheErrorPage(Request $request): bool|string
    {
        $path = $this->getFileFromRequest($request, self::ERROR_EXTENSION);

        if ($path === null) {
            return false;
        }

        return File::exists($path) ? File::get($path) : false;
    }

    public function shouldCachePage(Request $request, SymfonyResponse $response): bool
    {
        if (resolve(CacheBypassResolver::class)->shouldBypass()) {
            return false;
        }

        if (config('capell-html-cache.enabled', true) !== true) {
            return false;
        }

        if ($request->has('without_html_cache')) {
            return false;
        }

        if ($request->query->count() > 0) {
            return false;
        }

        if (resolve(ConfiguredHtmlCacheBypassRules::class)->shouldBypass($request)) {
            return false;
        }

        if ($this->isInertiaRequest($request)) {
            return false;
        }

        if (! $request->isMethod('GET')) {
            return false;
        }

        if ($this->safeRequestSegments($request) === null) {
            return false;
        }

        if ($this->sessionHasUserState($request)) {
            return false;
        }

        if (! in_array($response->getStatusCode(), [200, 404], true)) {
            return false;
        }

        if (mb_strpos((string) $response->headers->get('Content-Type'), 'text/html') === false) {
            return false;
        }

        if ($this->containsAuthoringSurface($request, (string) $response->getContent())) {
            return false;
        }

        return ! $request->headers->has('x-livewire');
    }

    public function forget(string $slug): bool
    {
        $deleted = false;

        foreach (['html', 'json', 'xml'] as $extension) {
            $deleted = $this->files->delete($this->getCachePath($slug . '.' . $extension)) || $deleted;
        }

        if ($this->files->delete($this->getCachePath($slug . self::ERROR_EXTENSION))) {
            return true;
        }

        return $deleted;
    }

    public function clear(?string $path = null): bool
    {
        return $this->files->deleteDirectory($this->getCachePath($path), preserve: true);
    }

    private function aliasFilename(?string $filename): string
    {
        if (in_array($filename, [null, '', 'index'], true)) {
            return 'pc__index__pc';
        }

        return $filename;
    }

    /**
     * @return array<array-key, mixed>|null
     */
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

        $filename = $this->aliasFilename(array_pop($segments));
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
            return $this->container->make('path.public') . '/page-cache';
        }

        return null;
    }

    private function getFileFromRequest(Request $request, string $extension = '.html'): ?string
    {
        $segments = $this->safeRequestSegments($request);

        if ($segments === null) {
            return null;
        }

        $filename = $this->aliasFilename(array_pop($segments));

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

    private function containsAuthoringSurface(Request $request, string $content): bool
    {
        if ($this->hasMatchingSafeInspection($request, $content)) {
            return false;
        }

        return resolve(PublicHtmlSafetyInspector::class)->containsAuthoringSurface($content);
    }

    private function hasMatchingSafeInspection(Request $request, string $content): bool
    {
        return $request->attributes->get(AssertPublicHtmlContainsNoAuthoringSurfaceAction::SAFE_INSPECTION_PASSED_ATTRIBUTE) === true
            && $request->attributes->get(AssertPublicHtmlContainsNoAuthoringSurfaceAction::SAFE_INSPECTION_HASH_ATTRIBUTE) === hash('xxh128', $content);
    }

    private function writeCacheFile(Request $request, string $path, string $content): void
    {
        $staleCachedUrlId = $request->attributes->get(HtmlCacheMiddleware::STALE_CACHE_ID_ATTRIBUTE);
        $claimToken = $request->attributes->get(HtmlCacheMiddleware::STALE_CACHE_CLAIM_TOKEN_ATTRIBUTE);

        if ($staleCachedUrlId === null && $claimToken === null) {
            $this->files->replace($path, $content);

            return;
        }

        $this->replaceCacheFileForCurrentStaleClaim($staleCachedUrlId, $claimToken, $path, $content);
    }

    private function replaceCacheFileForCurrentStaleClaim(mixed $staleCachedUrlId, mixed $claimToken, string $path, string $content): void
    {
        if (! is_numeric($staleCachedUrlId) || ! is_string($claimToken) || $claimToken === '') {
            return;
        }

        $temporaryPath = $this->temporaryPathForAtomicReplace($path);
        $bytesWritten = $this->files->put($temporaryPath, $content);

        if ($bytesWritten === false || $bytesWritten !== strlen($content)) {
            throw new RuntimeException(sprintf('Unable to write temporary cache file for "%s".', $path));
        }

        try {
            DB::transaction(function () use ($staleCachedUrlId, $claimToken, $path, $temporaryPath): void {
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
            });
        } finally {
            if ($this->files->exists($temporaryPath)) {
                $this->files->delete($temporaryPath);
            }
        }
    }

    private function temporaryPathForAtomicReplace(string $path): string
    {
        return dirname($path) . DIRECTORY_SEPARATOR . basename($path) . '.tmp.' . Str::uuid()->toString();
    }
}
