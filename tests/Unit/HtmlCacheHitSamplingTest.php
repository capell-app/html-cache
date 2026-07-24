<?php

declare(strict_types=1);

use Capell\HtmlCache\Actions\RecordHtmlCacheHitAction;
use Capell\HtmlCache\Jobs\FlushHtmlCacheHitBatchJob;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Support\Telemetry\HtmlCacheHitBuffer;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Lottery;

uses(HtmlCacheTestCase::class);

it('weights sampled production cache hits', function (): void {
    app()->detectEnvironment(static fn (): string => 'production');
    config(['capell-html-cache.hit_recording.sample_rate' => 10]);
    Queue::fake([FlushHtmlCacheHitBatchJob::class]);

    $request = Request::create('https://example.test/sampled', 'GET');
    $urlHash = CachedModelUrl::hashUrl($request->fullUrl());

    Lottery::alwaysWin(static fn () => RecordHtmlCacheHitAction::run($request, 12));

    expect(resolve(HtmlCacheHitBuffer::class)->snapshot($urlHash))
        ->hits->toBe(10)
        ->bytesServed->toBe(120);

    Queue::assertPushed(FlushHtmlCacheHitBatchJob::class, 1);
});
