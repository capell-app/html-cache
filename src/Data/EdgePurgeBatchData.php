<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Data;

/**
 * One provider-sized purge taken from a zone's pending set.
 */
final readonly class EdgePurgeBatchData
{
    /**
     * @param  array<string, int>  $urls  URL to the sequence it was queued at
     * @param  array<string, int>  $tags  tag to the sequence it was queued at
     * @param  int  $sequence  the newest sequence pending when the batch was taken
     */
    public function __construct(
        public bool $purgeAll,
        public array $urls,
        public array $tags,
        public int $sequence,
        public ?string $escalationReason = null,
    ) {}

    public function purge(): EdgeCachePurgeData
    {
        return new EdgeCachePurgeData(
            tags: array_map(strval(...), array_keys($this->tags)),
            urls: array_map(strval(...), array_keys($this->urls)),
            purgeAll: $this->purgeAll,
        );
    }

    public function kind(): string
    {
        return match (true) {
            $this->purgeAll => 'full_zone',
            $this->urls !== [] => 'urls',
            default => 'tags',
        };
    }

    public function size(): int
    {
        return $this->purgeAll ? 1 : count($this->urls) + count($this->tags);
    }
}
