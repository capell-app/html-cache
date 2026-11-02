<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Cache;

use Carbon\CarbonImmutable;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Proves a queue worker is polling the connection that refreshes stale HTML
 * copies. Stale-marking is only safe while one is running; otherwise a stale
 * copy would be served until something else cleared it.
 */
final class RefreshWorkerHeartbeat
{
    private const string KEY_PREFIX = 'capell-html-cache:refresh-worker:';

    public function record(Looping $event): void
    {
        try {
            // Looping fires on every poll; write at most once per quarter of the window.
            if (! Cache::add(self::KEY_PREFIX . 'throttle:' . $event->connectionName, true, max(1, intdiv($this->maximumAgeSeconds(), 4)))) {
                return;
            }
        } catch (Throwable) {
            return;
        }

        $this->touch($event->connectionName);
    }

    public function touch(string $connection): void
    {
        try {
            Cache::put(
                self::KEY_PREFIX . $connection,
                CarbonImmutable::now()->toIso8601String(),
                $this->maximumAgeSeconds() * 2,
            );
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }

    public function isAlive(string $connection): bool
    {
        try {
            $recordedAt = Cache::get(self::KEY_PREFIX . $connection);

            return is_string($recordedAt)
                && CarbonImmutable::parse($recordedAt)->diffInSeconds(CarbonImmutable::now(), true) <= $this->maximumAgeSeconds();
        } catch (Throwable) {
            return false;
        }
    }

    private function maximumAgeSeconds(): int
    {
        $seconds = config('capell-html-cache.origin_stale_while_revalidate.worker_heartbeat_seconds', 120);

        return is_numeric($seconds) ? max(4, (int) $seconds) : 120;
    }
}
