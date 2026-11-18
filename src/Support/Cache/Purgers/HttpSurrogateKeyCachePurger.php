<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Cache\Purgers;

use Capell\Core\Support\Http\OutboundHttp;
use Capell\Core\Support\Http\OutboundHttpPolicy;
use Capell\Frontend\Support\Cache\SurrogateKeyNormalizer;
use Capell\HtmlCache\Actions\ForgetCoveredEdgePurgeDropsAction;
use Capell\HtmlCache\Contracts\ReportsEdgePurgeResponses;
use Capell\HtmlCache\Data\EdgeCachePurgeData;
use Capell\HtmlCache\Data\EdgePurgeResponseData;
use Capell\HtmlCache\Support\Cache\EdgePurge\EdgePurgeLogger;
use InvalidArgumentException;
use Throwable;

final class HttpSurrogateKeyCachePurger implements ReportsEdgePurgeResponses
{
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
        $endpoint = config('capell-html-cache.purge.endpoint');
        $host = is_string($endpoint) ? parse_url(trim($endpoint), PHP_URL_HOST) : null;

        return is_string($host) && $host !== '' ? 'http:' . strtolower($host) : null;
    }

    public function sendPurge(EdgeCachePurgeData $purge): EdgePurgeResponseData
    {
        $endpoint = config('capell-html-cache.purge.endpoint');
        $normalizedKeys = SurrogateKeyNormalizer::normalize($purge->tags);

        if (! is_string($endpoint) || trim($endpoint) === '' || ($normalizedKeys === [] && $purge->urls === [] && ! $purge->purgeAll)) {
            return EdgePurgeResponseData::failed('HTTP edge purge is not configured or the purge was empty.');
        }

        $endpoint = trim($endpoint);

        try {
            $policy = $this->endpointPolicy($endpoint);
        } catch (Throwable $exception) {
            return EdgePurgeResponseData::fromException($exception);
        }

        // The guarded client pins the resolved public address, so a rebinding
        // DNS answer cannot send the purge token to an internal host.
        $request = OutboundHttp::guarded($policy)->acceptJson()->asJson();

        $token = config('capell-html-cache.purge.token');

        if (is_string($token) && $token !== '') {
            $request = $request->withToken($token);
        }

        $headerName = config('capell-html-cache.purge.surrogate_key_header', 'Surrogate-Key');
        $configuredMethod = config('capell-html-cache.purge.method', 'post');
        $method = is_string($configuredMethod) ? strtolower($configuredMethod) : 'post';

        try {
            $response = $request
                ->withHeaders([
                    is_string($headerName) && $headerName !== '' ? $headerName : 'Surrogate-Key' => implode(' ', $normalizedKeys),
                ])
                ->send(in_array($method, ['post', 'put', 'patch', 'delete'], true) ? strtoupper($method) : 'POST', $endpoint, [
                    'json' => [
                        'surrogate_keys' => $normalizedKeys,
                        'urls' => $purge->urls,
                        'purge_all' => $purge->purgeAll,
                    ],
                ]);
        } catch (Throwable $exception) {
            return EdgePurgeResponseData::fromException($exception);
        }

        return EdgePurgeResponseData::fromResponse($response, accepted: $response->successful());
    }

    private function timeoutSeconds(): int
    {
        $timeout = config('capell-html-cache.purge.timeout_seconds', 5);

        return is_numeric($timeout) ? max(1, (int) $timeout) : 5;
    }

    private function connectTimeoutSeconds(): int
    {
        return min(3, $this->timeoutSeconds());
    }

    /** The endpoint must be an absolute HTTPS URL; the guarded client enforces the rest. */
    private function endpointPolicy(string $url): OutboundHttpPolicy
    {
        $parts = parse_url($url);

        throw_if(
            ! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || ($parts['host'] ?? '') === '' || isset($parts['fragment']),
            InvalidArgumentException::class,
            'Cache purge endpoint must be an absolute HTTPS URL.',
        );

        return new OutboundHttpPolicy(
            schemes: ['https'],
            ports: [is_int($parts['port'] ?? null) ? $parts['port'] : 443],
            connectTimeoutSeconds: $this->connectTimeoutSeconds(),
            timeoutSeconds: $this->timeoutSeconds(),
        );
    }
}
