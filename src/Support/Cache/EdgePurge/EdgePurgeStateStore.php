<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Cache\EdgePurge;

use Capell\HtmlCache\Data\EdgePurgeThrottleSettingsData;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\NullStore;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The cache store every queue worker shares for edge purge pacing. All
 * read-modify-write steps run under an atomic per-zone lock.
 */
final readonly class EdgePurgeStateStore
{
    private const string PREFIX = 'capell-html-cache:edge-purge:';

    private const int LOCK_SECONDS = 10;

    private const int LOCK_WAIT_SECONDS = 5;

    public function __construct(private Repository $cache) {}

    public static function fromSettings(EdgePurgeThrottleSettingsData $settings): self
    {
        return new self(Cache::store($settings->store));
    }

    /**
     * Whether the configured store can pace purges for every worker: it must
     * offer atomic locks and outlive the process, so the array and null
     * stores never qualify.
     */
    public static function supportsLocks(EdgePurgeThrottleSettingsData $settings): bool
    {
        try {
            $store = Cache::store($settings->store)->getStore();
        } catch (Throwable) {
            return false;
        }

        return $store instanceof LockProvider
            && ! $store instanceof ArrayStore
            && ! $store instanceof NullStore;
    }

    public function key(string $zone, string $name): string
    {
        return self::PREFIX . hash('sha256', $zone) . ':' . $name;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     *
     * @throws LockTimeoutException
     */
    public function withLock(string $zone, string $name, callable $callback): mixed
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            return $callback();
        }

        return $store
            ->lock($this->key($zone, $name . ':lock'), self::LOCK_SECONDS)
            ->block(self::LOCK_WAIT_SECONDS, $callback);
    }

    /** @return array<string, mixed>|null */
    public function get(string $zone, string $name): ?array
    {
        $value = $this->cache->get($this->key($zone, $name));

        if (! is_array($value)) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /** @param array<string, mixed> $value */
    public function put(string $zone, string $name, array $value, ?int $ttlSeconds = null): void
    {
        if ($ttlSeconds === null) {
            $this->cache->forever($this->key($zone, $name), $value);

            return;
        }

        $this->cache->put($this->key($zone, $name), $value, $ttlSeconds);
    }

    public function forget(string $zone, string $name): void
    {
        $this->cache->forget($this->key($zone, $name));
    }
}
