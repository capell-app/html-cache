<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Cache\Purgers;

use Capell\Frontend\Support\Cache\SurrogateKeyNormalizer;
use Capell\HtmlCache\Contracts\CachePurger;
use Illuminate\Http\Client\Factory as HttpFactory;

final class HttpSurrogateKeyCachePurger implements CachePurger
{
    public function __construct(private readonly HttpFactory $http) {}

    /**
     * @param  list<string>  $surrogateKeys
     */
    public function purge(array $surrogateKeys): bool
    {
        $endpoint = config('capell-html-cache.purge.endpoint');
        $normalizedKeys = SurrogateKeyNormalizer::normalize($surrogateKeys);

        if (! is_string($endpoint) || trim($endpoint) === '' || $normalizedKeys === []) {
            return false;
        }

        $request = $this->http
            ->timeout($this->timeoutSeconds())
            ->acceptJson()
            ->asJson();

        $token = config('capell-html-cache.purge.token');

        if (is_string($token) && $token !== '') {
            $request = $request->withToken($token);
        }

        $headerName = config('capell-html-cache.purge.surrogate_key_header', 'Surrogate-Key');
        $method = strtolower((string) config('capell-html-cache.purge.method', 'post'));

        $response = $request
            ->withHeaders([
                is_string($headerName) && $headerName !== '' ? $headerName : 'Surrogate-Key' => implode(' ', $normalizedKeys),
            ])
            ->send(in_array($method, ['post', 'put', 'patch', 'delete'], true) ? strtoupper($method) : 'POST', trim($endpoint), [
                'json' => [
                    'surrogate_keys' => $normalizedKeys,
                ],
            ]);

        return $response->successful();
    }

    private function timeoutSeconds(): int
    {
        $timeout = config('capell-html-cache.purge.timeout_seconds', 5);

        return is_numeric($timeout) ? max(1, (int) $timeout) : 5;
    }
}
