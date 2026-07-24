<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\HtmlCache\Jobs\FlushHtmlCacheHitBatchJob;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Support\Telemetry\HtmlCacheHitBuffer;
use Illuminate\Http\Request;
use Illuminate\Support\Lottery;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static void run(Request $request, int $bytesServed)
 */
final class RecordHtmlCacheHitAction
{
    use AsFake;
    use AsObject;

    public function handle(Request $request, int $bytesServed): void
    {
        if (config('capell-html-cache.hit_recording.enabled', true) !== true) {
            return;
        }

        $urlHash = CachedModelUrl::hashUrl($request->fullUrl());
        $configuredSampleRate = config('capell-html-cache.hit_recording.sample_rate', 10);
        $sampleRate = app()->environment('testing')
            ? 1
            : max(1, is_numeric($configuredSampleRate) ? (int) $configuredSampleRate : 10);

        if ($sampleRate > 1 && Lottery::odds(1, $sampleRate)->choose() !== true) {
            return;
        }

        if (! resolve(HtmlCacheHitBuffer::class)->record($urlHash, $bytesServed, $sampleRate)) {
            return;
        }

        $configuredDelay = config('capell-html-cache.hit_recording.flush_delay_seconds', 30);
        $delay = is_numeric($configuredDelay) ? max(1, (int) $configuredDelay) : 30;

        FlushHtmlCacheHitBatchJob::dispatch($urlHash)
            ->delay($delay)
            ->afterCommit();
    }
}
