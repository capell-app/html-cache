<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Cache\EdgePurge;

use Capell\Frontend\Support\Cache\SurrogateKeyNormalizer;
use Capell\HtmlCache\Data\EdgeCachePurgeData;
use Capell\HtmlCache\Data\EdgePurgeBatchData;
use Capell\HtmlCache\Data\EdgePurgeDroppedData;
use Capell\HtmlCache\Data\EdgePurgeEnqueueResultData;
use Capell\HtmlCache\Data\EdgePurgePendingStatusData;
use Capell\HtmlCache\Data\EdgePurgeThrottleSettingsData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * The deduplicated set of purges waiting to be sent for one edge zone, and
 * the claim held by the single drain job that sends them.
 *
 * Every queued URL and tag remembers the sequence it was queued at, so a
 * batch only removes what it actually sent: anything queued again while the
 * request was in flight stays pending and is sent again.
 *
 * @phpstan-type PendingEntry array{0: int, 1: int, 2: int, 3: int}
 * @phpstan-type PendingState array{sequence: int, purge_all: PendingEntry|null, urls: array<string, PendingEntry>, tags: array<string, PendingEntry>, escalated: string|null}
 */
final readonly class EdgePurgePendingQueue
{
    private const string PENDING = 'pending';

    private const string DRAINER = 'drainer';

    private const string DROPPED = 'dropped';

    /** Extra claim lifetime beyond the drain job's own delay. */
    private const int CLAIM_GRACE_SECONDS = 120;

    public function __construct(
        private EdgePurgeThrottleSettingsData $settings,
        private EdgePurgeStateStore $store,
    ) {}

    public static function fromSettings(EdgePurgeThrottleSettingsData $settings): self
    {
        return new self($settings, EdgePurgeStateStore::fromSettings($settings));
    }

    public function add(string $zone, EdgeCachePurgeData $purge): EdgePurgeEnqueueResultData
    {
        return $this->store->withLock($zone, self::PENDING, function () use ($zone, $purge): EdgePurgeEnqueueResultData {
            $now = $this->now();
            $state = $this->read($zone);
            $sequence = $state['sequence'] + 1;
            $state['sequence'] = $sequence;

            if ($purge->purgeAll) {
                // A full-zone purge supersedes every targeted purge queued before it.
                $state['purge_all'] = [$sequence, $state['purge_all'][1] ?? $this->oldestQueuedAt($state) ?? $now, 0, 0];
                $state['urls'] = [];
                $state['tags'] = [];
            } else {
                // Kept even while a full-zone purge is pending: that purge may
                // already be in flight and reach the edge before this change.
                foreach (array_unique($purge->urls) as $url) {
                    $state['urls'][$url] = [$sequence, $state['urls'][$url][1] ?? $now, 0, 0];
                }

                foreach (SurrogateKeyNormalizer::normalize($purge->tags) as $tag) {
                    $state['tags'][$tag] = [$sequence, $state['tags'][$tag][1] ?? $now, 0, 0];
                }
            }

            $escalation = null;

            if ($state['purge_all'] === null
                && $this->settings->escalateToFullPurge
                && $this->pendingRequests($state) > $this->settings->escalatePendingThreshold) {
                $escalation = 'pending_threshold';
                $state = $this->escalate($state, $escalation, $sequence);
            }

            $this->write($zone, $state);

            return new EdgePurgeEnqueueResultData(
                pendingCount: $this->pendingCount($state),
                drainerToken: $this->claimDrainer($zone),
                escalationReason: $escalation,
            );
        });
    }

    /**
     * Replace the pending purges with one full-zone purge when the oldest
     * has waited longer than configured. Returns the reason when it did.
     */
    public function escalateIfOverdue(string $zone): ?string
    {
        if (! $this->settings->escalateToFullPurge) {
            return null;
        }

        return $this->store->withLock($zone, self::PENDING, function () use ($zone): ?string {
            $state = $this->read($zone);
            $oldest = $this->oldestQueuedAt($state);

            if ($state['purge_all'] !== null
                || $oldest === null
                || $this->now() - $oldest < $this->settings->escalateAfterSeconds) {
                return null;
            }

            $this->write($zone, $this->escalate($state, 'queue_age', $state['sequence']));

            return 'queue_age';
        });
    }

    public function next(string $zone): ?EdgePurgeBatchData
    {
        return $this->store->withLock($zone, self::PENDING, function () use ($zone): ?EdgePurgeBatchData {
            $state = $this->read($zone);

            if ($state['purge_all'] !== null) {
                return new EdgePurgeBatchData(
                    purgeAll: true,
                    urls: [],
                    tags: [],
                    sequence: $state['sequence'],
                    escalationReason: $state['escalated'],
                );
            }

            if ($state['urls'] !== []) {
                return new EdgePurgeBatchData(
                    purgeAll: false,
                    urls: $this->take($state['urls'], $this->settings->maxUrlsPerRequest),
                    tags: [],
                    sequence: $state['sequence'],
                );
            }

            if ($state['tags'] !== []) {
                return new EdgePurgeBatchData(
                    purgeAll: false,
                    urls: [],
                    tags: $this->take($state['tags'], $this->settings->maxTagsPerRequest),
                    sequence: $state['sequence'],
                );
            }

            return null;
        });
    }

    /**
     * Remove what a successful batch sent, keeping anything queued again
     * after the batch was taken.
     */
    public function complete(string $zone, EdgePurgeBatchData $batch): void
    {
        $this->store->withLock($zone, self::PENDING, function () use ($zone, $batch): void {
            $state = $this->read($zone);

            if ($batch->purgeAll) {
                $sent = static fn (array $entry): bool => $entry[0] > $batch->sequence;
                $state['urls'] = array_filter($state['urls'], $sent);
                $state['tags'] = array_filter($state['tags'], $sent);

                if ($state['purge_all'] !== null && $state['purge_all'][0] <= $batch->sequence) {
                    $state['purge_all'] = null;
                    $state['escalated'] = null;
                }

            }

            $this->forgetDropped($zone, $batch->purgeAll, array_map(strval(...), [...array_keys($batch->urls), ...array_keys($batch->tags)]));

            foreach ($batch->urls as $url => $sequence) {
                if (($state['urls'][$url][0] ?? null) === $sequence) {
                    unset($state['urls'][$url]);
                }
            }

            foreach ($batch->tags as $tag => $sequence) {
                if (($state['tags'][$tag][0] ?? null) === $sequence) {
                    unset($state['tags'][$tag]);
                }
            }

            $this->write($zone, $state);
        });
    }

    /**
     * Confirm a drain job may send the zone's purges. A job whose claim lapsed
     * while it waited in a busy queue takes it back, unless another drain
     * job has claimed the zone since.
     */
    public function assumeDrainer(string $zone, string $token): bool
    {
        return $this->store->withLock($zone, self::PENDING, function () use ($zone, $token): bool {
            if ($this->store->get($zone, self::DRAINER) === null) {
                $this->putClaim($zone, $token, $this->settings->coalesceWindowSeconds);
            }

            return $this->ownsDrainer($zone, $token);
        });
    }

    /**
     * Handle a batch the provider refused as a bad request. A batch of
     * several entries is split in half, so the offending entry is isolated
     * without dropping its batch-mates. A single entry moves behind the other
     * pending purges and is dropped, and recorded, after repeated refusals.
     *
     * @return list<string> the entries given up
     */
    public function reject(string $zone, EdgePurgeBatchData $batch, ?int $status): array
    {
        return $this->store->withLock($zone, self::PENDING, function () use ($zone, $batch, $status): array {
            $state = $this->read($zone);
            $dropped = [];
            $splitSize = (int) ceil($batch->size() / 2);

            foreach (['urls' => $batch->urls, 'tags' => $batch->tags] as $kind => $items) {
                foreach ($items as $item => $sequence) {
                    $item = (string) $item;
                    $entry = $state[$kind][$item] ?? null;

                    if ($entry === null || $entry[0] !== $sequence) {
                        continue;
                    }

                    if ($batch->size() > 1) {
                        $state[$kind][$item] = [$entry[0], $entry[1], $entry[2], $splitSize];

                        continue;
                    }

                    unset($state[$kind][$item]);
                    $failures = $entry[2] + 1;

                    if ($failures >= $this->settings->maxBatchFailures) {
                        $dropped[] = $item;

                        continue;
                    }

                    $state[$kind][$item] = [$entry[0], $entry[1], $failures, 1];
                }
            }

            $this->write($zone, $state);

            if ($dropped !== []) {
                $this->recordDropped($zone, $dropped, $status);
            }

            return $dropped;
        });
    }

    public function dropped(string $zone): EdgePurgeDroppedData
    {
        return EdgePurgeDroppedData::fromState($this->store->get($zone, self::DROPPED));
    }

    /**
     * Give up a drain job's claim, so the next purge starts a new drainer.
     */
    public function forgetDrainer(string $zone, string $token): void
    {
        $this->store->withLock($zone, self::PENDING, function () use ($zone, $token): void {
            if ($this->ownsDrainer($zone, $token)) {
                $this->store->forget($zone, self::DRAINER);
            }
        });
    }

    public function ownsDrainer(string $zone, string $token): bool
    {
        $claim = $this->store->get($zone, self::DRAINER);

        return ($claim['token'] ?? null) === $token;
    }

    /**
     * Keep the drainer claim alive while its job waits to run again.
     */
    public function renewDrainer(string $zone, string $token, int $delaySeconds): void
    {
        $this->store->withLock($zone, self::PENDING, function () use ($zone, $token, $delaySeconds): void {
            if ($this->ownsDrainer($zone, $token)) {
                $this->putClaim($zone, $token, $delaySeconds);
            }
        });
    }

    /**
     * Give up the drainer claim once nothing is pending. Returns false when
     * purges arrived meanwhile, so the drainer keeps going.
     */
    public function releaseDrainerIfEmpty(string $zone, string $token): bool
    {
        return $this->store->withLock($zone, self::PENDING, function () use ($zone, $token): bool {
            if ($this->pendingCount($this->read($zone)) > 0) {
                return false;
            }

            if ($this->ownsDrainer($zone, $token)) {
                $this->store->forget($zone, self::DRAINER);
            }

            return true;
        });
    }

    public function status(string $zone): EdgePurgePendingStatusData
    {
        $state = $this->read($zone);

        return new EdgePurgePendingStatusData(
            count: $this->pendingCount($state),
            purgeAll: $state['purge_all'] !== null,
            oldestQueuedAt: $this->oldestQueuedAt($state),
            hasDrainer: $this->store->get($zone, self::DRAINER) !== null,
            escalationReason: $state['escalated'],
        );
    }

    /**
     * Forget dropped purges that a later successful purge covered: all of
     * them after a full-zone purge, otherwise the URLs and tags it sent.
     */
    public function forgetCoveredDrops(string $zone, EdgeCachePurgeData $purge): void
    {
        $this->store->withLock($zone, self::PENDING, function () use ($zone, $purge): void {
            $this->forgetDropped($zone, $purge->purgeAll, array_values([...$purge->urls, ...SurrogateKeyNormalizer::normalize($purge->tags)]));
        });
    }

    /** @param list<string> $items */
    private function recordDropped(string $zone, array $items, ?int $status): void
    {
        $this->putDropped($zone, $this->dropped($zone)->withDropped($items, $this->now(), $status));
    }

    /** @param list<string> $items */
    private function forgetDropped(string $zone, bool $everything, array $items): void
    {
        $dropped = $this->dropped($zone);

        if ($dropped->count === 0) {
            return;
        }

        $remaining = $everything ? new EdgePurgeDroppedData : $dropped->without($items);

        if ($remaining->count === 0) {
            $this->store->forget($zone, self::DROPPED);

            return;
        }

        if ($remaining->count !== $dropped->count) {
            $this->putDropped($zone, $remaining);
        }
    }

    /**
     * The record lives for the configured lifetime from its latest drop, so
     * a purge made outside Capell cannot leave the warning forever.
     */
    private function putDropped(string $zone, EdgePurgeDroppedData $dropped): void
    {
        $remaining = ($dropped->lastDroppedAt ?? $this->now()) + $this->settings->droppedRecordTtlSeconds - $this->now();

        if ($remaining <= 0) {
            $this->store->forget($zone, self::DROPPED);

            return;
        }

        $this->store->put($zone, self::DROPPED, $dropped->toState(), $remaining);
    }

    private function claimDrainer(string $zone): ?string
    {
        if ($this->store->get($zone, self::DRAINER) !== null) {
            return null;
        }

        $token = (string) Str::uuid();
        $this->putClaim($zone, $token, $this->settings->coalesceWindowSeconds);

        return $token;
    }

    private function putClaim(string $zone, string $token, int $delaySeconds): void
    {
        $this->store->put($zone, self::DRAINER, ['token' => $token], max(1, $delaySeconds) + self::CLAIM_GRACE_SECONDS);
    }

    /**
     * @param  PendingState  $state
     * @return PendingState
     */
    private function escalate(array $state, string $reason, int $sequence): array
    {
        $state['purge_all'] = [$sequence, $this->oldestQueuedAt($state) ?? $this->now(), 0, 0];
        $state['escalated'] = $reason;
        $state['urls'] = [];
        $state['tags'] = [];

        return $state;
    }

    /**
     * Take the next batch in queue order, no larger than the smallest batch
     * size any of its entries has been split down to.
     *
     * @param  array<string, PendingEntry>  $entries
     * @return array<string, int>
     */
    private function take(array $entries, int $maximum): array
    {
        $batch = [];
        $limit = $maximum;

        foreach ($entries as $item => $entry) {
            $limit = min($limit, $entry[3] > 0 ? $entry[3] : $maximum);

            if (count($batch) >= $limit) {
                break;
            }

            $batch[$item] = $entry[0];
        }

        return $batch;
    }

    /**
     * The provider requests needed to send what is pending. Escalation counts
     * these rather than items: a provider takes a whole batch per request, so
     * 501 clears needing six requests are cheaper than one full-zone purge.
     *
     * @param  PendingState  $state
     */
    private function pendingRequests(array $state): int
    {
        return ($state['purge_all'] !== null ? 1 : 0)
            + (int) ceil(count($state['urls']) / $this->settings->maxUrlsPerRequest)
            + (int) ceil(count($state['tags']) / $this->settings->maxTagsPerRequest);
    }

    /** @param PendingState $state */
    private function pendingCount(array $state): int
    {
        return ($state['purge_all'] !== null ? 1 : 0) + count($state['urls']) + count($state['tags']);
    }

    /** @param PendingState $state */
    private function oldestQueuedAt(array $state): ?int
    {
        $times = [
            ...array_map(static fn (array $entry): int => $entry[1], array_values($state['urls'])),
            ...array_map(static fn (array $entry): int => $entry[1], array_values($state['tags'])),
        ];

        if ($state['purge_all'] !== null) {
            $times[] = $state['purge_all'][1];
        }

        return $times === [] ? null : min($times);
    }

    /** @return PendingState */
    private function read(string $zone): array
    {
        $stored = $this->store->get($zone, self::PENDING) ?? [];

        return [
            'sequence' => is_int($stored['sequence'] ?? null) ? $stored['sequence'] : 0,
            'purge_all' => $this->entry($stored['purge_all'] ?? null),
            'urls' => $this->entries($stored['urls'] ?? null),
            'tags' => $this->entries($stored['tags'] ?? null),
            'escalated' => is_string($stored['escalated'] ?? null) ? $stored['escalated'] : null,
        ];
    }

    /** @param PendingState $state */
    private function write(string $zone, array $state): void
    {
        if ($this->pendingCount($state) === 0) {
            // Keep the sequence so a batch taken earlier never matches a newer entry.
            $state['urls'] = [];
            $state['tags'] = [];
            $state['purge_all'] = null;
            $state['escalated'] = null;
        }

        $this->store->put($zone, self::PENDING, $state);
    }

    /** @return PendingEntry|null */
    private function entry(mixed $value): ?array
    {
        return is_array($value) && is_int($value[0] ?? null) && is_int($value[1] ?? null)
            ? [$value[0], $value[1], is_int($value[2] ?? null) ? $value[2] : 0, is_int($value[3] ?? null) ? $value[3] : 0]
            : null;
    }

    /** @return array<string, PendingEntry> */
    private function entries(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $entries = [];

        foreach ($value as $key => $entry) {
            $entry = $this->entry($entry);

            if ($entry !== null) {
                $entries[(string) $key] = $entry;
            }
        }

        return $entries;
    }

    private function now(): int
    {
        return CarbonImmutable::now()->getTimestamp();
    }
}
