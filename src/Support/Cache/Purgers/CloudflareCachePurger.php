<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Cache\Purgers;

use Capell\Frontend\Support\Cache\SurrogateKeyNormalizer;
use Capell\HtmlCache\Contracts\CachePurger;
use Capell\HtmlCache\Data\EdgeCachePurgeData;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

final class CloudflareCachePurger implements CachePurger
{
    private const int MAX_OPERATIONS_PER_REQUEST = 100;

    public function __construct(private readonly HttpFactory $http) {}

    public function purge(EdgeCachePurgeData $purge): bool
    {
        $zoneId = config('capell-html-cache.purge.cloudflare.zone_id');
        $token = config('capell-html-cache.purge.token');

        if (! is_string($zoneId) || ! is_string($token)) {
            return false;
        }

        $zoneId = trim($zoneId);
        $token = trim($token);

        if (preg_match('/^[a-f0-9]{32}$/i', $zoneId) !== 1 || $token === '') {
            return false;
        }

        $payloads = $this->payloads($purge);

        if ($payloads === []) {
            return false;
        }

        try {
            foreach ($payloads as $payload) {
                $response = $this->http
                    ->connectTimeout(3)
                    ->timeout($this->timeoutSeconds())
                    ->acceptJson()
                    ->asJson()
                    ->withToken($token)
                    ->post(sprintf('https://api.cloudflare.com/client/v4/zones/%s/purge_cache', $zoneId), $payload);

                if (! $response->successful() || $response->json('success') !== true) {
                    return false;
                }
            }

            return true;
        } catch (Throwable) {
            return false;
        }
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

        foreach (array_chunk($urls, self::MAX_OPERATIONS_PER_REQUEST) as $urlBatch) {
            $payloads[] = ['files' => $urlBatch];
        }

        foreach (array_chunk($tags, self::MAX_OPERATIONS_PER_REQUEST) as $tagBatch) {
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
