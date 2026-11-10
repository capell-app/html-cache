<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Data;

/**
 * Purges given up after the provider refused them as bad requests. The edge
 * may still serve old copies of these until they are purged again, a
 * full-zone purge covers them, or the record's lifetime lapses.
 */
final readonly class EdgePurgeDroppedData
{
    public const int MAX_TRACKED_ITEMS = 500;

    private const int SAMPLE_SIZE = 10;

    public int $count;

    public ?int $lastDroppedAt;

    /** @var list<string> the most recently dropped URLs or tags */
    public array $sample;

    /**
     * @param  array<string, int>  $items  dropped URL or tag to when it was dropped
     * @param  int  $overflow  dropped entries no longer tracked individually
     */
    public function __construct(
        public array $items = [],
        public int $overflow = 0,
        public ?int $lastStatus = null,
    ) {
        $this->count = count($items) + $overflow;
        $this->lastDroppedAt = $items === [] ? null : max($items);
        $recent = $items;
        arsort($recent);
        $this->sample = array_slice(array_map(strval(...), array_keys($recent)), 0, self::SAMPLE_SIZE);
    }

    /** @param array<string, mixed>|null $state */
    public static function fromState(?array $state): self
    {
        $items = [];

        foreach (is_array($state['items'] ?? null) ? $state['items'] : [] as $item => $droppedAt) {
            if (is_int($droppedAt)) {
                $items[(string) $item] = $droppedAt;
            }
        }

        return new self(
            items: $items,
            overflow: is_int($state['overflow'] ?? null) ? $state['overflow'] : 0,
            lastStatus: is_int($state['last_status'] ?? null) ? $state['last_status'] : null,
        );
    }

    /**
     * @param  list<string>  $items
     */
    public function withDropped(array $items, int $droppedAt, ?int $status): self
    {
        $tracked = $this->items;

        foreach ($items as $item) {
            unset($tracked[$item]);
            $tracked[$item] = $droppedAt;
        }

        $overflow = $this->overflow + max(0, count($tracked) - self::MAX_TRACKED_ITEMS);

        return new self(array_slice($tracked, -self::MAX_TRACKED_ITEMS, null, true), $overflow, $status);
    }

    /**
     * @param  list<string>  $items  URLs or tags that have since been purged successfully
     */
    public function without(array $items): self
    {
        return new self(array_diff_key($this->items, array_flip($items)), $this->overflow, $this->lastStatus);
    }

    /** @return array{items: array<string, int>, overflow: int, last_status: int|null} */
    public function toState(): array
    {
        return [
            'items' => $this->items,
            'overflow' => $this->overflow,
            'last_status' => $this->lastStatus,
        ];
    }
}
