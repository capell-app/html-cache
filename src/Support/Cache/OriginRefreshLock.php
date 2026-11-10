<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Cache;

use Closure;
use Illuminate\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use LogicException;
use ReflectionMethod;
use Throwable;

/**
 * Admission for rendering a stale origin copy.
 *
 * Ordinary background refreshes share a small number of slots, so refreshes for
 * different URLs and sites proceed side by side. Two kinds of work need the origin
 * to themselves and take the whole lock instead: a deployment's refresher, which
 * replaces every copy and holds the lock for its run, and the overdue-stale
 * expiry, which clears copies and must not race a render. Exclusive work takes
 * the gate first, which makes every new ordinary job defer, then waits for the
 * slots already in use to drain, then holds the gate and every slot. Because it
 * owns every slot before it works, correctness never rests on a check made
 * earlier: a job that slipped past the gate check simply makes the exclusive
 * holder wait for its slot.
 *
 * The slot count is read each time, so changing it takes full effect only after
 * the queues drain: exclusive work covers the slots as configured when it starts,
 * and a job admitted under a larger earlier count can still finish alongside it.
 *
 * Every acquisition is atomic on the cache store's own lock, and release and
 * renewal are owner-checked inside the store (a Lua script on Redis, a conditional
 * update on the database), so a holder whose lock lapsed can never delete or
 * extend the lock of whoever took it next.
 *
 * A capped exclusive holder keeps the lock only while it renews it. The cap is
 * measured on the monotonic clock, so neither a frozen nor an adjusted
 * application clock can stretch it, and every backend expiry is clamped to the
 * time that is left. A stuck or abandoned holder therefore always gives the lock
 * back. A store whose locks cannot be refreshed holds a capped lock for the whole
 * cap from the start instead of renewing it. Callers use {@see self::exclusive()},
 * which always clears that state, so it cannot leak into the next job a long-lived
 * worker runs.
 *
 * Known limit: the Database lock driver stores its expiry with the application
 * clock, so on that driver a holder that dies while the application clock is set
 * far ahead can leave the lock held beyond the cap. Redis, which production uses,
 * expires on the server's own clock.
 */
final class OriginRefreshLock
{
    public const string KEY = 'capell-html-cache:origin-refresh:lock';

    public const int RENEW_EVERY_SECONDS = 20;

    /**
     * The longest an ordinary slot should be held without renewal. A holder that dies leaves its
     * slot behind for this long, so exclusive work must wait longer than this to outlast it.
     */
    public const int MAX_SLOT_TTL_SECONDS = 120;

    /** Set while exclusive work holds or is taking the gate, so ordinary jobs defer instead of queueing behind it. */
    private const string GATE_MARKER = 'capell-html-cache:origin-refresh:gate';

    /** Names the capped (deployment) holder, so a visitor hint can tell it from the expiry. */
    private const string REFRESHER_MARKER = 'capell-html-cache:origin-refresh:refresher';

    /** A capped lease must outlast this many renewal intervals, or one slow render could lose it. */
    private const int MINIMUM_CAPPED_TTL_SECONDS = self::RENEW_EVERY_SECONDS * 3;

    private const int POLL_MICROSECONDS = 250_000;

    private const int NANOSECONDS = 1_000_000_000;

    /** @var array{owner: string, keys: list<string>, ttl: int, deadline: int|null, renewedAt: int, refreshable: bool}|null */
    private static ?array $held = null;

    /** @var (Closure(): int)|null Nanosecond clock override, for tests only. */
    private static ?Closure $clock = null;

    /**
     * Test seam: the cap is measured on the monotonic clock, which tests cannot
     * move. Pass a closure returning nanoseconds to drive it, or null to restore
     * the real clock; either way any lease this process holds is forgotten.
     *
     * @param  (Closure(): int)|null  $clock
     */
    public static function reset(?Closure $clock = null): void
    {
        self::$clock = $clock;
        self::$held = null;
    }

    public function slotCount(): int
    {
        $configured = config('capell-html-cache.origin_stale_while_revalidate.refresh_slots', 3);

        return is_numeric($configured) ? max(1, (int) $configured) : 3;
    }

    /**
     * One atomic attempt at an ordinary refresh slot. False while exclusive work
     * holds or is taking the lock, or when every slot is in use.
     */
    public function tryAcquireSlot(string $owner, int $ttlSeconds): bool
    {
        $this->assertOwner($owner, $ttlSeconds);

        if ($this->isExclusiveHeld()) {
            return false;
        }

        foreach ($this->slotKeys() as $key) {
            if (! Cache::lock($key, $ttlSeconds, $owner)->get()) {
                continue;
            }

            // Politeness only: exclusive work that started meanwhile waits for this slot itself.
            if ($this->isExclusiveHeld()) {
                $this->lock($key, 1, $owner)->release();

                return false;
            }

            return true;
        }

        return false;
    }

    /**
     * One atomic attempt at the whole lock: the gate and every slot. All or
     * nothing; a partial acquisition is rolled back.
     */
    public function tryAcquireExclusive(string $owner, int $ttlSeconds, ?int $hardCapSeconds = null): bool
    {
        if (! $this->takeGate($owner, $ttlSeconds, $hardCapSeconds)) {
            return false;
        }

        if ($this->takeSlots($owner)) {
            return true;
        }

        $this->release($owner);

        return false;
    }

    /**
     * Waits for the whole lock. New ordinary jobs defer from the moment the gate
     * is taken, so the slots in use drain and the wait is bounded by one render.
     */
    public function acquireExclusive(string $owner, int $ttlSeconds, int $waitSeconds, ?int $hardCapSeconds = null): bool
    {
        // Real elapsed time bounds the wait; neither the application clock nor the test clock may stretch it.
        $giveUpAt = $this->realNanos() + (max(0, $waitSeconds) * self::NANOSECONDS);

        while (! $this->takeGate($owner, $ttlSeconds, $hardCapSeconds)) {
            if ($this->realNanos() >= $giveUpAt) {
                return false;
            }

            Sleep::usleep(self::POLL_MICROSECONDS);
        }

        while (! $this->takeSlots($owner)) {
            // The wait can outlast the lease, so keep the gate and the slots taken so far alive
            // and give up the moment any of them is lost.
            if ($this->realNanos() >= $giveUpAt || ! $this->heartbeat($owner)) {
                $this->release($owner);

                return false;
            }

            // The first marking can lose a race with a probe, so retry it on every poll.
            $this->remarkGate($owner);

            Sleep::usleep(self::POLL_MICROSECONDS);
        }

        return true;
    }

    /**
     * Runs $work holding the whole lock, and always gives it back and forgets the
     * lease afterwards. Returns null when the lock could not be taken in time.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T|null
     */
    public function exclusive(string $owner, int $ttlSeconds, int $waitSeconds, ?int $hardCapSeconds, Closure $work): mixed
    {
        if (! $this->acquireExclusive($owner, $ttlSeconds, $waitSeconds, $hardCapSeconds)) {
            return null;
        }

        try {
            return $work();
        } finally {
            $this->release($owner);
        }
    }

    /**
     * Extends the holder's lock by its TTL, but never past the hard cap of a
     * capped holder. False when the caller no longer owns it.
     */
    public function renew(string $owner, int $ttlSeconds): bool
    {
        $held = self::$held !== null && self::$held['owner'] === $owner ? self::$held : null;

        if ($held !== null && $held['deadline'] !== null) {
            $remaining = (int) ceil(($held['deadline'] - $this->nanos()) / self::NANOSECONDS);

            if ($remaining < 1) {
                $this->release($owner);

                return false;
            }

            $ttlSeconds = min($ttlSeconds, $remaining);
        }

        if ($held === null) {
            // An ordinary slot: renew whichever one this owner holds.
            foreach ($this->slotKeys() as $key) {
                $lock = $this->lock($key, $ttlSeconds, $owner);

                if ($lock->isOwnedByCurrentProcess() && $this->refresh($lock, $ttlSeconds)) {
                    return true;
                }
            }

            return false;
        }

        foreach ($held['keys'] as $key) {
            $lock = $this->lock($key, $ttlSeconds, $owner);

            if (! $held['refreshable'] ? ! $lock->isOwnedByCurrentProcess() : ! $lock->refresh($ttlSeconds)) {
                return false;
            }
        }

        $this->markGate($owner, $ttlSeconds, $held['deadline'] !== null);

        return true;
    }

    public function release(string $owner): bool
    {
        $keys = self::$held !== null && self::$held['owner'] === $owner
            ? self::$held['keys']
            : [...$this->slotKeys(), self::KEY];

        if (self::$held !== null && self::$held['owner'] === $owner) {
            self::$held = null;
        }

        // Owner-checked atomically inside the store, like the lock itself.
        foreach ([self::GATE_MARKER, self::REFRESHER_MARKER] as $marker) {
            $this->lock($marker, 1, $owner)->release();
        }

        $released = false;

        foreach ($keys as $key) {
            $released = $this->lock($key, 1, $owner)->release() || $released;
        }

        return $released;
    }

    /**
     * Renews the lock this process holds as a capped refresher, at most every few
     * seconds. Null when this process holds no capped lock, true while it is still
     * held, false once it has been lost or its hard cap has passed: the caller must
     * stop rendering.
     */
    public function renewOwned(): ?bool
    {
        $held = self::$held;

        if ($held === null || $held['deadline'] === null) {
            return null;
        }

        if ($this->nanos() - $held['renewedAt'] < self::RENEW_EVERY_SECONDS * self::NANOSECONDS && $this->nanos() < $held['deadline']) {
            return $this->lock(self::KEY, 1, $held['owner'])->isOwnedByCurrentProcess() ?: $this->lose();
        }

        if ($this->renew($held['owner'], $held['ttl'])) {
            if (self::$held !== null) {
                self::$held['renewedAt'] = $this->nanos();
            }

            return true;
        }

        return $this->lose();
    }

    /**
     * True while exclusive work (a deployment refresher or the expiry) holds or is taking the lock.
     *
     * @phpstan-impure It reads shared cache state that other processes change.
     */
    public function isExclusiveHeld(): bool
    {
        // Probing by acquisition respects expiry on every store; some drivers keep
        // reporting an expired record as locked until the next acquisition.
        $probe = $this->lock(self::GATE_MARKER, 1, 'probe:' . bin2hex(random_bytes(8)));

        if ($probe->get()) {
            $probe->release();

            return false;
        }

        return true;
    }

    /**
     * True while a capped refresher (a deployment) holds the lock. Only a hint for
     * visitor dispatch: the expiry or an ordinary job holding it does not count,
     * because those cover only some URLs and others still need their own job.
     */
    public function isRefresherHeld(): bool
    {
        return $this->lock(self::REFRESHER_MARKER, 1)->isLocked();
    }

    /** Refreshes what this process holds, at most every few seconds; false once any of it was lost. */
    private function heartbeat(string $owner): bool
    {
        $held = self::$held;

        if ($held === null || $held['owner'] !== $owner) {
            return false;
        }

        if ($this->nanos() - $held['renewedAt'] < self::RENEW_EVERY_SECONDS * self::NANOSECONDS) {
            return $this->ownsHeldKeys($owner);
        }

        if (! $this->renew($owner, $held['ttl'])) {
            return false;
        }

        if (self::$held !== null) {
            self::$held['renewedAt'] = $this->nanos();
        }

        return true;
    }

    private function ownsHeldKeys(string $owner): bool
    {
        foreach (self::$held['keys'] ?? [] as $key) {
            if (! $this->lock($key, 1, $owner)->isOwnedByCurrentProcess()) {
                return false;
            }
        }

        return true;
    }

    private function takeGate(string $owner, int $ttlSeconds, ?int $hardCapSeconds): bool
    {
        $this->assertOwner($owner, $ttlSeconds);

        if ($hardCapSeconds !== null && ($hardCapSeconds < 1 || $ttlSeconds < min(self::MINIMUM_CAPPED_TTL_SECONDS, $hardCapSeconds))) {
            throw new InvalidArgumentException(sprintf('A capped origin refresh lock needs a hard cap of at least one second and a TTL of at least %d seconds, so one slow render cannot lose it.', self::MINIMUM_CAPPED_TTL_SECONDS));
        }

        if (self::$held !== null && self::$held['owner'] === $owner) {
            return true;
        }

        $refreshable = $this->supportsRefresh($owner);
        $backendTtl = $hardCapSeconds === null ? $ttlSeconds : ($refreshable ? min($ttlSeconds, $hardCapSeconds) : $hardCapSeconds);

        if (! Cache::lock(self::KEY, $backendTtl, $owner)->get()) {
            return false;
        }

        $now = $this->nanos();
        self::$held = [
            'owner' => $owner,
            'keys' => [self::KEY],
            'ttl' => $hardCapSeconds === null ? $ttlSeconds : min($ttlSeconds, $hardCapSeconds),
            'deadline' => $hardCapSeconds === null ? null : $now + ($hardCapSeconds * self::NANOSECONDS),
            'renewedAt' => $now,
            'refreshable' => $refreshable,
        ];
        $this->markGate($owner, $backendTtl, $hardCapSeconds !== null);

        return true;
    }

    /** Takes whichever slots are free; true once the owner holds every one. */
    private function takeSlots(string $owner): bool
    {
        $held = self::$held;

        if ($held === null || $held['owner'] !== $owner) {
            return false;
        }

        $ttl = $held['deadline'] === null || $held['refreshable']
            ? $held['ttl']
            : (int) ceil(($held['deadline'] - $this->nanos()) / self::NANOSECONDS);

        foreach ($this->slotKeys() as $key) {
            if (in_array($key, self::$held['keys'] ?? [], true)) {
                continue;
            }

            if (Cache::lock($key, max(1, $ttl), $owner)->get() && self::$held !== null) {
                self::$held['keys'][] = $key;
            }
        }

        // Ownership, not our own bookkeeping, decides: a lapsed lease must not count.
        return count(self::$held['keys'] ?? []) === $this->slotCount() + 1 && $this->ownsHeldKeys($owner);
    }

    private function remarkGate(string $owner): void
    {
        $held = self::$held;

        if ($held !== null && $held['owner'] === $owner) {
            $this->markGate($owner, $held['ttl'], $held['deadline'] !== null);
        }
    }

    private function markGate(string $owner, int $ttlSeconds, bool $capped): void
    {
        foreach ($capped ? [self::GATE_MARKER, self::REFRESHER_MARKER] : [self::GATE_MARKER] as $marker) {
            $lock = $this->lock($marker, $ttlSeconds, $owner);

            if ($lock->isOwnedByCurrentProcess()) {
                $this->refresh($lock, $ttlSeconds);
            } else {
                $lock->get();
            }
        }
    }

    /** @return list<string> */
    private function slotKeys(): array
    {
        return array_map(static fn (int $slot): string => self::KEY . ':slot:' . $slot, range(1, $this->slotCount()));
    }

    private function assertOwner(string $owner, int $ttlSeconds): void
    {
        if (trim($owner) === '' || $ttlSeconds < 1) {
            throw new InvalidArgumentException('An origin refresh lock needs an owner and a TTL of at least one second.');
        }
    }

    private function nanos(): int
    {
        return self::$clock instanceof Closure ? (self::$clock)() : $this->realNanos();
    }

    private function realNanos(): int
    {
        return (int) hrtime(true);
    }

    private function lose(): false
    {
        self::$held = null;

        return false;
    }

    /** Every store's lock extends this class, which carries owner checks, refresh and the locked test. */
    private function lock(string $key, int $ttlSeconds, ?string $owner = null): Lock
    {
        $lock = Cache::lock($key, $ttlSeconds, $owner);

        if (! $lock instanceof Lock) {
            throw new LogicException('The cache store returned a lock that cannot report ownership.');
        }

        return $lock;
    }

    private function refresh(Lock $lock, int $ttlSeconds): bool
    {
        try {
            return $lock->refresh($ttlSeconds);
        } catch (Throwable) {
            // A store whose locks cannot be extended keeps the slot for its original TTL.
            return $lock->isOwnedByCurrentProcess();
        }
    }

    private function supportsRefresh(string $owner): bool
    {
        return new ReflectionMethod(Cache::lock(self::KEY, 1, $owner), 'refresh')->getDeclaringClass()->getName() !== Lock::class;
    }
}
