<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\HtmlCache\Contracts\CachePurger;
use Capell\HtmlCache\Contracts\ReportsEdgePurgeResponses;
use Capell\HtmlCache\Data\EdgePurgeBatchData;
use Capell\HtmlCache\Data\EdgePurgeDrainResultData;
use Capell\HtmlCache\Data\EdgePurgeResponseData;
use Capell\HtmlCache\Data\EdgePurgeThrottleSettingsData;
use Capell\HtmlCache\Data\EdgePurgeZoneRateData;
use Capell\HtmlCache\Enums\EdgePurgeOutcome;
use Capell\HtmlCache\Support\Cache\EdgePurge\EdgePurgeLogger;
use Capell\HtmlCache\Support\Cache\EdgePurge\EdgePurgePendingQueue;
use Capell\HtmlCache\Support\Cache\EdgePurge\EdgePurgeRateGovernor;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Bus\PendingDispatch;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsJob;
use Lorisleiva\Actions\Concerns\AsObject;
use Lorisleiva\Actions\Decorators\JobDecorator;
use Throwable;

/**
 * Send one edge zone's pending purges in provider-sized batches, paced by the
 * zone's shared token bucket. When the bucket is empty or the provider
 * refuses, the job finishes and dispatches its successor for the wait the
 * provider asked for, so waiting never runs into a retry deadline or fails.
 *
 * @method static EdgePurgeDrainResultData run(string $zone, string $drainerToken)
 * @method static PendingDispatch dispatch(string $zone, string $drainerToken)
 */
final class DrainEdgeCachePurgeQueueAction
{
    use AsFake;
    use AsJob;
    use AsObject;

    /** Real exceptions still fail the job; throttling never throws. */
    public int $jobMaxExceptions = 5;

    public function getJobRetryUntil(): DateTimeInterface
    {
        return CarbonImmutable::now()->addSeconds(EdgePurgeThrottleSettingsData::fromConfig()->drainRetryUntilSeconds);
    }

    public function asJob(JobDecorator $job, string $zone, string $drainerToken): void
    {
        try {
            $result = $this->handle($zone, $drainerToken);
        } catch (LockTimeoutException) {
            self::dispatch($zone, $drainerToken)->delay(1);

            return;
        }

        if (! $result->finished()) {
            // A fresh job, not a release: each one gets its own retry deadline.
            self::dispatch($zone, $drainerToken)->delay($result->releaseAfterSeconds ?? 1);
        }
    }

    /**
     * A drain job that really failed gives up its claim, so the next purge
     * starts a new drainer instead of waiting for the claim to lapse.
     */
    public function jobFailed(Throwable $exception, string $zone, string $drainerToken): void
    {
        EdgePurgePendingQueue::fromSettings(EdgePurgeThrottleSettingsData::fromConfig())->forgetDrainer($zone, $drainerToken);
    }

    public function handle(string $zone, string $drainerToken): EdgePurgeDrainResultData
    {
        $settings = EdgePurgeThrottleSettingsData::fromConfig();
        $queue = EdgePurgePendingQueue::fromSettings($settings);
        $logger = new EdgePurgeLogger($settings);

        if (! $queue->assumeDrainer($zone, $drainerToken)) {
            // Another drain job claimed the zone after this one's claim lapsed.
            return new EdgePurgeDrainResultData;
        }

        $purger = $this->purger();

        if (! $purger instanceof ReportsEdgePurgeResponses || $purger->edgePurgeZone() !== $zone) {
            $logger->warning('HTML cache edge purge zone is no longer configured; its pending purges were not sent.', [
                'zone' => $zone,
                'pending' => $queue->status($zone)->count,
            ]);
            $queue->releaseDrainerIfEmpty($zone, $drainerToken);

            return new EdgePurgeDrainResultData;
        }

        $governor = EdgePurgeRateGovernor::fromSettings($settings);
        $sent = 0;

        for ($attempt = 0; $attempt < $settings->maxBatchesPerDrain; $attempt++) {
            $escalation = $queue->escalateIfOverdue($zone);

            if ($escalation !== null) {
                $logger->warning('HTML cache edge purge escalated to a full-zone purge.', [
                    'zone' => $zone,
                    'reason' => $escalation,
                    'max_age_seconds' => $settings->escalateAfterSeconds,
                ]);
            }

            $batch = $queue->next($zone);

            if (! $batch instanceof EdgePurgeBatchData) {
                if ($queue->releaseDrainerIfEmpty($zone, $drainerToken)) {
                    return new EdgePurgeDrainResultData(sentBatches: $sent);
                }

                continue;
            }

            $wait = $governor->reserve($zone);

            if ($wait > 0.0) {
                return $this->releaseAfter($queue, $zone, $drainerToken, (int) ceil($wait), $sent);
            }

            $response = $purger->sendPurge($batch->purge());
            $rate = $governor->record($zone, $response);
            $this->logResponse($logger, $zone, $batch, $response, $rate);

            if ($response->outcome === EdgePurgeOutcome::Succeeded) {
                $queue->complete($zone, $batch);
                $sent++;

                continue;
            }

            if ($response->isBadRequest() && ! $batch->purgeAll) {
                $dropped = $queue->reject($zone, $batch, $response->status);

                if ($dropped !== []) {
                    $logger->error('HTML cache edge purge gave up on purges the provider keeps refusing as bad requests; the edge may serve stale copies until a full-zone purge.', [
                        'zone' => $zone,
                        'kind' => $batch->kind(),
                        'items' => count($dropped),
                        'sample' => array_slice($dropped, 0, 10),
                        'attempts' => $settings->maxBatchFailures,
                        ...$response->toLogContext(),
                    ]);
                }

                // The refusal concerned this batch's content, not the provider,
                // so carry on with the split or the next purges at once.
                continue;
            }

            if ($response->isCredentialFailure()) {
                $logger->error('HTML cache edge purge credentials were refused; purges are kept and retried until the token is fixed.', [
                    'zone' => $zone,
                    'kind' => $batch->kind(),
                    'items' => $batch->size(),
                    ...$response->toLogContext(),
                ]);
            }

            return $this->releaseAfter(
                $queue,
                $zone,
                $drainerToken,
                $response->outcome === EdgePurgeOutcome::Throttled
                    ? $settings->clampRetryAfter($response->requestedWaitSeconds())
                    : $settings->failureBackoffSeconds,
                $sent,
            );
        }

        // This run's batch budget is spent; continue on a fresh job attempt.
        return $this->releaseAfter($queue, $zone, $drainerToken, 1, $sent);
    }

    private function releaseAfter(EdgePurgePendingQueue $queue, string $zone, string $drainerToken, int $seconds, int $sent): EdgePurgeDrainResultData
    {
        $seconds = max(1, $seconds);
        $queue->renewDrainer($zone, $drainerToken, $seconds);

        return new EdgePurgeDrainResultData(sentBatches: $sent, releaseAfterSeconds: $seconds);
    }

    private function logResponse(
        EdgePurgeLogger $logger,
        string $zone,
        EdgePurgeBatchData $batch,
        EdgePurgeResponseData $response,
        EdgePurgeZoneRateData $rate,
    ): void {
        $context = [
            'zone' => $zone,
            'kind' => $batch->kind(),
            'items' => $batch->size(),
            'escalation_reason' => $batch->escalationReason,
            ...$response->toLogContext(),
            'learned_requests_per_second' => round($rate->requestsPerSecond, 4),
            'ceiling_requests_per_second' => $rate->ceilingRequestsPerSecond,
            'blocked_until' => $rate->blockedUntil,
        ];

        match ($response->outcome) {
            EdgePurgeOutcome::Succeeded => $logger->info('HTML cache edge purge sent.', $context),
            EdgePurgeOutcome::Throttled => $logger->warning('HTML cache edge purge was rate limited by the provider; it will be retried.', $context),
            EdgePurgeOutcome::Failed => $logger->warning('HTML cache edge purge failed; it will be retried.', $context),
        };
    }

    /**
     * Typed as the contract: the binding picks the driver from runtime config.
     */
    private function purger(): CachePurger
    {
        return resolve(CachePurger::class);
    }
}
