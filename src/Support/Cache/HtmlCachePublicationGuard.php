<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Cache;

use Capell\Core\Actions\PublicRead\PublishPublicArtefactAction;
use Capell\Core\Contracts\PublicRead\PublicArtefactFence;
use Capell\Core\Data\PublicRead\PublicReadStampData;
use Capell\Core\Models\Site;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\Core\Support\PublicRead\PublicArtefactPermit;
use Capell\Frontend\Support\Loader\SiteLoader;
use Capell\Frontend\Support\Loader\SiteResolver;
use Capell\HtmlCache\Models\HtmlCacheModelWithdrawal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Override;
use RuntimeException;
use Throwable;

/**
 * Rendering happens outside the lock. Only publication and hard invalidation
 * share it, so a render started before withdrawal cannot restore deleted HTML.
 * The lock lives outside the page directory and has no expiring lease.
 */
final class HtmlCachePublicationGuard implements PublicArtefactFence
{
    public const string REQUEST_TOKEN_ATTRIBUTE = 'capell.html_cache.publication_token';

    public const string REQUEST_SITE_ATTRIBUTE = 'capell.html_cache.publication_site';

    public const string REJECTED_ATTRIBUTE = 'capell.html_cache.publication_rejected';

    /** Model keys ("morph:id") a render retrieved, so publication can refuse renders of withdrawn models. */
    public const string RENDERED_MODELS_ATTRIBUTE = 'capell.html_cache.rendered_models';

    private const string STAMP_SEPARATOR = '#';

    private const int MODEL_KEY_CHUNK = 500;

    private bool $withdrawalsInstalled = false;

    private bool $withdrawalsProbed = false;

    /** @var Collection<int, Site>|null The sites a request can resolve to, read once per guard (scoped to one request). */
    private ?Collection $sites = null;

    /** @var array<string, true> */
    private static array $heldLocks = [];

    /** @var resource|null The lock handle while exclusive() runs. */
    private mixed $exclusiveHandle = null;

    /** The withdrawal scope for a URL: every query variant of one host and path. */
    public static function urlKey(Request $request): string
    {
        return hash('sha256', mb_strtolower($request->getHost()) . '/' . trim($request->path(), '/'));
    }

    public static function urlKeyForUrl(string $url): string
    {
        return self::urlKey(Request::create($url));
    }

    public static function modelKey(string $morphClass, int|string $modelKey): string
    {
        return $morphClass . ':' . $modelKey;
    }

    /** @return list<string> the models a render retrieved; see RENDERED_MODELS_ATTRIBUTE */
    public static function renderedModelKeys(Request $request): array
    {
        $renderedModels = $request->attributes->get(self::RENDERED_MODELS_ATTRIBUTE, []);

        return is_array($renderedModels) ? array_values(array_filter($renderedModels, is_string(...))) : [];
    }

    public function capture(Request $request): ?string
    {
        if ($request->attributes->has(self::REQUEST_TOKEN_ATTRIBUTE)) {
            return $this->token($request);
        }

        // The revision fence is taken first, before anything renders. The site
        // is then resolved from the request, because capture runs ahead of the
        // frontend: another site's schedule must not bound this site's stamp.
        $token = $this->snapshot(self::urlKey($request));
        $token = $token === null ? null : $token . self::STAMP_SEPARATOR . PublicReadDiskCopyPolicy::buildKey($this->requestSiteId($request));

        $request->attributes->set(self::REQUEST_TOKEN_ATTRIBUTE, $token);

        return $token;
    }

    public function token(Request $request): ?string
    {
        $token = $request->attributes->get(self::REQUEST_TOKEN_ATTRIBUTE);

        return is_string($token) ? $token : null;
    }

    /**
     * A token binds the site-wide generation, the URL's withdrawal counter when
     * a URL is given, and the model withdrawal sequence. A targeted withdrawal
     * advances only the counters of the URLs it removes and the sequence of the
     * models it withdraws, so it rejects renders of those URLs and renders that
     * retrieved those models without rejecting any other publish.
     */
    public function snapshot(?string $urlKey = null): ?string
    {
        try {
            return $this->locked(function ($handle) use ($urlKey): string {
                $generation = $this->generation($handle);

                return implode('|', [
                    $generation,
                    $urlKey ?? '',
                    $urlKey === null ? '' : (string) ($this->urlCounters()[$urlKey] ?? 0),
                    (string) $this->withdrawalSequence(),
                ]);
            });
        } catch (Throwable $throwable) {
            report($throwable);

            return null;
        }
    }

    /**
     * Publishes through Core's PublishPublicArtefactAction, the one fenced
     * path for copies served without Laravel: under this guard's lock (the
     * lock every withdrawal holds) it checks the public-read stamp the render
     * was built under against a fresh one (refusing changed or time-bounded
     * rules), then this guard's own generation and URL counter, then writes
     * with the permit the page cache store requires.
     *
     * @param  Closure(PublicArtefactPermit): bool  $publish
     * @param  list<string>  $renderedModelKeys  models the render retrieved; see modelKey()
     */
    public function publish(?string $token, Closure $publish, array $renderedModelKeys = []): bool
    {
        if ($token === null) {
            return false;
        }

        $parts = explode(self::STAMP_SEPARATOR, $token, 2);
        $builtUnder = PublicReadStampData::fromKey($parts[1] ?? '');

        try {
            return PublishPublicArtefactAction::run($this, PublicReadDiskCopyPolicy::siteId(), $builtUnder, [], function (PublicArtefactPermit $permit) use ($parts, $publish, $renderedModelKeys): bool {
                [$generation, $urlKey, $counter, $sequence] = array_pad(explode('|', $parts[0], 4), 4, null);

                $handle = $this->exclusiveHandle;

                if ($handle === null) {
                    throw new RuntimeException('HTML cache copies are published only under the publication guard lock.');
                }

                if (! hash_equals($this->generation($handle), (string) $generation)) {
                    return false;
                }

                if ($urlKey !== null && $urlKey !== '' && (string) ($this->urlCounters()[$urlKey] ?? 0) !== $counter) {
                    return false;
                }

                // Tokens from before model fencing carry no sequence; treat them as the oldest.
                if ($this->anyWithdrawnAfter($renderedModelKeys, is_numeric($sequence) ? (int) $sequence : 0)) {
                    return false;
                }

                return $publish($permit);
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

    /**
     * Fence a targeted withdrawal without rotating the site-wide generation.
     *
     * Each model key records a withdrawal whose sequence is newer than every
     * token captured so far, rejecting renders that retrieved that model
     * before the withdrawal, and whose time lets a dependency index recorded
     * late detect that its page may hold withdrawn content. Each URL key's
     * counter advances, rejecting renders of that URL holding an older token.
     * Renders of other URLs that did not retrieve the models still publish.
     *
     * @param  list<string>  $urlKeys  see urlKey()
     * @param  list<string>  $modelKeys  see modelKey()
     */
    public function fence(array $urlKeys, array $modelKeys = []): void
    {
        if ($modelKeys !== []) {
            if (! $this->withdrawalsInstalled()) {
                throw new RuntimeException('The HTML cache model withdrawal table is not installed.');
            }

            $withdrawnAt = CarbonImmutable::now();
            foreach (array_chunk(array_values(array_unique($modelKeys)), self::MODEL_KEY_CHUNK) as $chunk) {
                HtmlCacheModelWithdrawal::query()->insert(array_map(
                    static fn (string $modelKey): array => ['model_key' => $modelKey, 'withdrawn_at' => $withdrawnAt],
                    $chunk,
                ));
            }
        }

        if ($urlKeys === []) {
            return;
        }

        $this->locked(function () use ($urlKeys): void {
            $counters = $this->urlCounters();
            foreach (array_unique($urlKeys) as $urlKey) {
                $counters[$urlKey] = ($counters[$urlKey] ?? 0) + 1;
            }
            $this->writeUrlCounters($counters);
        });
    }

    public function withdrawalsInstalled(): bool
    {
        if ($this->withdrawalsInstalled) {
            return true;
        }

        // Only a positive answer is remembered: an installed table does not
        // disappear. The first answer comes from the request's shared schema
        // probe (migrations forget it); every later one is read afresh, so a
        // table installed meanwhile is found. A probe that fails throws.
        $installed = resolve(RuntimeSchemaState::class)->hasTableOrFail(
            (new HtmlCacheModelWithdrawal)->getTable(),
            refresh: $this->withdrawalsProbed,
        );
        $this->withdrawalsProbed = true;

        return $this->withdrawalsInstalled = $installed;
    }

    /**
     * Delete artefacts that fence() has already protected, under the lock and
     * without rotating the site-wide generation. A failure part-way through is
     * unknowable, so it rotates to stay conservative.
     *
     * @param  Closure(): bool  $withdraw
     */
    public function withdrawFenced(Closure $withdraw): bool
    {
        return $this->locked(function ($handle) use ($withdraw): bool {
            try {
                return $withdraw();
            } catch (Throwable $throwable) {
                $this->rotate($handle);

                throw $throwable;
            }
        });
    }

    /**
     * Whether any of the models was withdrawn at or after the given time.
     *
     * @param  list<string>  $modelKeys  see modelKey()
     */
    public function anyWithdrawnSince(array $modelKeys, CarbonInterface $since): bool
    {
        if ($modelKeys === [] || ! $this->withdrawalsInstalled()) {
            return false;
        }

        foreach (array_chunk(array_values(array_unique($modelKeys)), self::MODEL_KEY_CHUNK) as $chunk) {
            if (HtmlCacheModelWithdrawal::query()->whereIn('model_key', $chunk)->where('withdrawn_at', '>=', $since)->exists()) {
                return true;
            }
        }

        return false;
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

    #[Override]
    public function exclusive(Closure $callback): mixed
    {
        return $this->locked(function ($handle) use ($callback): mixed {
            $this->exclusiveHandle = $handle;

            try {
                return $callback();
            } finally {
                $this->exclusiveHandle = null;
            }
        });
    }

    #[Override]
    public function fenceIdentity(): string
    {
        return 'html-cache:' . $this->lockPath();
    }

    /** The site the request is served by, retained on the request so the stamp and the publish agree. */
    private function requestSiteId(Request $request): ?int
    {
        if ($request->attributes->has(self::REQUEST_SITE_ATTRIBUTE)) {
            $retained = $request->attributes->get(self::REQUEST_SITE_ATTRIBUTE);

            return is_int($retained) ? $retained : null;
        }

        $siteId = PublicReadDiskCopyPolicy::siteId();

        if ($siteId === null) {
            try {
                [$site] = SiteResolver::resolve($request->fullUrl(), $this->sites ??= SiteLoader::getSites());
                $key = $site instanceof Site ? $site->getKey() : null;
                $siteId = is_int($key) ? $key : null;
            } catch (Throwable) {
                // An unresolvable site keeps the all-sites stamp, which is the stricter one.
                $siteId = null;
            }
        }

        $request->attributes->set(self::REQUEST_SITE_ATTRIBUTE, $siteId);

        return $siteId;
    }

    /**
     * @template T
     *
     * @param  Closure(resource): T  $operation
     * @return T
     */
    private function locked(Closure $operation): mixed
    {
        // capell-security-scan: local-stream reads the publication lock file in application storage
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

    private function withdrawalSequence(): int
    {
        if (! $this->withdrawalsInstalled()) {
            return 0;
        }

        $sequence = HtmlCacheModelWithdrawal::query()->max('id');

        return is_numeric($sequence) ? (int) $sequence : 0;
    }

    /** @param list<string> $modelKeys */
    private function anyWithdrawnAfter(array $modelKeys, int $sequence): bool
    {
        if ($modelKeys === [] || ! $this->withdrawalsInstalled()) {
            return false;
        }

        foreach (array_chunk(array_values(array_unique($modelKeys)), self::MODEL_KEY_CHUNK) as $chunk) {
            if (HtmlCacheModelWithdrawal::query()->whereIn('model_key', $chunk)->where('id', '>', $sequence)->exists()) {
                return true;
            }
        }

        return false;
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
