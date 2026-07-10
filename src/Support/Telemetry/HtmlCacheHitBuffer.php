<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Telemetry;

use Capell\HtmlCache\Data\HtmlCacheHitBatchData;
use Illuminate\Contracts\Cache\Repository;

final readonly class HtmlCacheHitBuffer
{
    private const string CACHE_PREFIX = 'capell-html-cache:hits:';

    public function __construct(
        private Repository $cache,
    ) {}

    public function record(string $urlHash, int $bytesServed): bool
    {
        $timeToLive = $this->bufferTimeToLive();

        $this->cache->add($this->hitsKey($urlHash), 0, $timeToLive);
        $this->cache->add($this->bytesKey($urlHash), 0, $timeToLive);
        $this->cache->increment($this->hitsKey($urlHash));
        $this->cache->increment($this->bytesKey($urlHash), max(0, $bytesServed));

        return $this->claimFlush($urlHash);
    }

    public function snapshot(string $urlHash): HtmlCacheHitBatchData
    {
        return new HtmlCacheHitBatchData(
            hits: $this->integerValue($this->cache->get($this->hitsKey($urlHash))),
            bytesServed: $this->integerValue($this->cache->get($this->bytesKey($urlHash))),
        );
    }

    public function acknowledge(string $urlHash, HtmlCacheHitBatchData $batch): bool
    {
        if ($batch->hits > 0) {
            $this->cache->decrement($this->hitsKey($urlHash), $batch->hits);
        }

        if ($batch->bytesServed > 0) {
            $this->cache->decrement($this->bytesKey($urlHash), $batch->bytesServed);
        }

        $this->cache->forget($this->scheduledKey($urlHash));

        return $this->snapshot($urlHash)->hasHits() && $this->claimFlush($urlHash);
    }

    public function releaseEmptyBatch(string $urlHash): void
    {
        $this->cache->forget($this->scheduledKey($urlHash));
    }

    private function claimFlush(string $urlHash): bool
    {
        return $this->cache->add($this->scheduledKey($urlHash), true, $this->bufferTimeToLive());
    }

    private function hitsKey(string $urlHash): string
    {
        return self::CACHE_PREFIX . $urlHash . ':count';
    }

    private function bytesKey(string $urlHash): string
    {
        return self::CACHE_PREFIX . $urlHash . ':bytes';
    }

    private function scheduledKey(string $urlHash): string
    {
        return self::CACHE_PREFIX . $urlHash . ':scheduled';
    }

    private function bufferTimeToLive(): int
    {
        $configured = config('capell-html-cache.hit_recording.buffer_ttl_seconds', 3600);

        return is_numeric($configured) ? max(60, (int) $configured) : 3600;
    }

    private function integerValue(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }
}
