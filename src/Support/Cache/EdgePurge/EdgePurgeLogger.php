<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Cache\EdgePurge;

use Capell\HtmlCache\Data\EdgeCachePurgeData;
use Capell\HtmlCache\Data\EdgePurgeResponseData;
use Capell\HtmlCache\Data\EdgePurgeThrottleSettingsData;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

/**
 * Structured log entries for the edge purge pipeline, on the configured
 * channel. Entries carry the zone, provider status and learned rate, never
 * credentials.
 */
final readonly class EdgePurgeLogger
{
    public function __construct(private EdgePurgeThrottleSettingsData $settings) {}

    /**
     * Record a purge the provider did not accept, with its status,
     * Retry-After and the size of the refused purge.
     */
    public static function directPurgeRejected(?string $zone, EdgeCachePurgeData $purge, EdgePurgeResponseData $response): void
    {
        new self(EdgePurgeThrottleSettingsData::fromConfig())->warning('HTML cache edge purge was not accepted by the provider.', [
            'zone' => $zone,
            'kind' => $purge->purgeAll ? 'full_zone' : 'targeted',
            'items' => $purge->purgeAll ? 1 : count($purge->urls) + count($purge->tags),
            ...$response->toLogContext(),
        ]);
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context): void
    {
        $this->logger()->info($message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context): void
    {
        $this->logger()->error($message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context): void
    {
        $this->logger()->warning($message, $context);
    }

    private function logger(): LoggerInterface
    {
        return Log::channel($this->settings->logChannel);
    }
}
