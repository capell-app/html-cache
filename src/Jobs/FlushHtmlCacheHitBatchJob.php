<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Jobs;

use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Support\Telemetry\HtmlCacheHitBuffer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

final class FlushHtmlCacheHitBatchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public function __construct(
        public readonly string $urlHash,
    ) {}

    public function handle(): void
    {
        $buffer = resolve(HtmlCacheHitBuffer::class);
        $batch = $buffer->snapshot($this->urlHash);

        if (! $batch->hasHits()) {
            $buffer->releaseEmptyBatch($this->urlHash);

            return;
        }

        CachedModelUrl::query()
            ->where('url_hash', $this->urlHash)
            ->update([
                'hit_count' => DB::raw('hit_count + ' . $batch->hits),
                'bytes_served' => DB::raw('bytes_served + ' . $batch->bytesServed),
                'last_hit_at' => now(),
            ]);

        if ($buffer->acknowledge($this->urlHash, $batch)) {
            self::dispatch($this->urlHash)
                ->delay($this->flushDelaySeconds())
                ->afterCommit();
        }
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [1, 5, 15, 30];
    }

    private function flushDelaySeconds(): int
    {
        $configured = config('capell-html-cache.hit_recording.flush_delay_seconds', 30);

        return is_numeric($configured) ? max(1, (int) $configured) : 30;
    }
}
