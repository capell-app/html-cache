<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Cache\Purgers;

use Capell\Frontend\Support\Cache\SurrogateKeyNormalizer;
use Capell\HtmlCache\Actions\ForgetCoveredEdgePurgeDropsAction;
use Capell\HtmlCache\Contracts\ReportsEdgePurgeResponses;
use Capell\HtmlCache\Data\EdgeCachePurgeData;
use Capell\HtmlCache\Data\EdgePurgeResponseData;
use Capell\HtmlCache\Data\EdgePurgeThrottleSettingsData;
use Capell\HtmlCache\Support\Cache\EdgePurge\EdgePurgeLogger;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

final class CloudflareCachePurger implements ReportsEdgePurgeResponses
{
    public function __construct(private readonly HttpFactory $http) {}

    public function purge(EdgeCachePurgeData $purge): bool
    {
        $response = $this->sendPurge($purge);

        if (! $response->isSuccessful()) {
            EdgePurgeLogger::directPurgeRejected($this->edgePurgeZone(), $purge, $response);

            return false;
        }

        ForgetCoveredEdgePurgeDropsAction::run($this->edgePurgeZone(), $purge);

        return true;
    }

    public function edgePurgeZone(): ?string
    {
        $credentials = $this->credentials();

        return $credentials === null ? null : 'cloudflare:' . strtolower($credentials['zone_id']);
    }

    public function sendPurge(EdgeCachePurgeData $purge): EdgePurgeResponseData
    {
        $credentials = $this->credentials();

        if ($credentials === null) {
            return EdgePurgeResponseData::failed('Cloudflare edge purge is not configured.');
        }

        $payloads = $this->payloads($purge);

        if ($payloads === []) {
            return EdgePurgeResponseData::failed('The purge contained no valid URLs or tags.');
        }

        $result = EdgePurgeResponseData::succeeded();

        try {
            foreach ($payloads as $payload) {
                $response = $this->http
                    ->connectTimeout(3)
                    ->timeout($this->timeoutSeconds())
                    ->acceptJson()
                    ->asJson()
                    ->withToken($credentials['token'])
                    ->post(sprintf('https://api.cloudflare.com/client/v4/zones/%s/purge_cache', $credentials['zone_id']), $payload);

                $result = EdgePurgeResponseData::fromResponse(
                    $response,
                    accepted: $response->successful() && $response->json('success') === true,
                );

                if (! $result->isSuccessful()) {
                    return $result;
                }
            }

            return $result;
        } catch (Throwable $exception) {
            return EdgePurgeResponseData::fromException($exception);
        }
    }

    /** @return array{zone_id: string, token: string}|null */
    private function credentials(): ?array
    {
        $zoneId = config('capell-html-cache.purge.cloudflare.zone_id');
        $token = config('capell-html-cache.purge.token');

        if (! is_string($zoneId) || ! is_string($token)) {
            return null;
        }

        $zoneId = trim($zoneId);
        $token = trim($token);

        if (preg_match('/^[a-f0-9]{32}$/i', $zoneId) !== 1 || $token === '') {
            return null;
        }

        return ['zone_id' => $zoneId, 'token' => $token];
    }

    /** @return list<array<string, bool|list<string>>> */
    private function payloads(EdgeCachePurgeData $purge): array
    {
        if ($purge->purgeAll) {
            return [['purge_everything' => true]];
        }

        $urls = array_values(array_unique(array_filter(
            $purge->urls,
            static fn (string $url): bool => filter_var($url, FILTER_VALIDATE_URL) !== false
                && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true),
        )));
        $tags = array_values(SurrogateKeyNormalizer::normalize($purge->tags));
        $payloads = [];

        $settings = EdgePurgeThrottleSettingsData::fromConfig();

        foreach (array_chunk($urls, max(1, $settings->maxUrlsPerRequest)) as $urlBatch) {
            $payloads[] = ['files' => $urlBatch];
        }

        foreach (array_chunk($tags, max(1, $settings->maxTagsPerRequest)) as $tagBatch) {
            $payloads[] = ['tags' => $tagBatch];
        }

        return $payloads;
    }

    private function timeoutSeconds(): int
    {
        $timeout = config('capell-html-cache.purge.timeout_seconds', 5);

        return is_numeric($timeout) ? max(1, (int) $timeout) : 5;
    }
}
