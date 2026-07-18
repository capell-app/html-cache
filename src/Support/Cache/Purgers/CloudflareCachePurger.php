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
    public function __construct(private readonly HttpFactory $http) {}

    public function purge(EdgeCachePurgeData $purge): bool
    {
        $zoneId = config('capell-html-cache.purge.cloudflare.zone_id');
        $token = config('capell-html-cache.purge.token');

        if (! is_string($zoneId) || preg_match('/^[a-f0-9]{32}$/i', $zoneId) !== 1 || ! is_string($token) || $token === '') {
            return false;
        }

        $payload = $this->payload($purge);

        if ($payload === null) {
            return false;
        }

        try {
            $response = $this->http
                ->connectTimeout(3)
                ->timeout($this->timeoutSeconds())
                ->acceptJson()
                ->asJson()
                ->withToken($token)
                ->post(sprintf('https://api.cloudflare.com/client/v4/zones/%s/purge_cache', $zoneId), $payload);

            return $response->successful() && $response->json('success') === true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, bool|list<string>>|null */
    private function payload(EdgeCachePurgeData $purge): ?array
    {
        if ($purge->purgeAll) {
            return ['purge_everything' => true];
        }

        $urls = array_values(array_unique(array_filter(
            $purge->urls,
            static fn (string $url): bool => filter_var($url, FILTER_VALIDATE_URL) !== false,
        )));

        if ($urls !== []) {
            return ['files' => $urls];
        }

        $tags = array_values(SurrogateKeyNormalizer::normalize($purge->tags));

        return $tags === [] ? null : ['tags' => $tags];
    }

    private function timeoutSeconds(): int
    {
        $timeout = config('capell-html-cache.purge.timeout_seconds', 5);

        return is_numeric($timeout) ? max(1, (int) $timeout) : 5;
    }
}
