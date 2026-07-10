<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Cache\Purgers;

use Capell\Frontend\Support\Cache\SurrogateKeyNormalizer;
use Capell\HtmlCache\Contracts\CachePurger;
use Illuminate\Http\Client\Factory as HttpFactory;
use InvalidArgumentException;
use Throwable;

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

        try {
            $resolvedEndpoint = $this->resolveEndpoint(trim($endpoint));
        } catch (Throwable) {
            return false;
        }

        $request = $this->http
            ->connectTimeout($this->connectTimeoutSeconds())
            ->timeout($this->timeoutSeconds())
            ->withoutRedirecting()
            ->acceptJson()
            ->asJson()
            ->withHeaders(['Host' => $resolvedEndpoint['host_header']])
            ->withOptions([
                'curl' => [
                    CURLOPT_RESOLVE => [$resolvedEndpoint['curl_resolve']],
                ],
            ]);

        $token = config('capell-html-cache.purge.token');

        if (is_string($token) && $token !== '') {
            $request = $request->withToken($token);
        }

        $headerName = config('capell-html-cache.purge.surrogate_key_header', 'Surrogate-Key');
        $configuredMethod = config('capell-html-cache.purge.method', 'post');
        $method = is_string($configuredMethod) ? strtolower($configuredMethod) : 'post';

        $response = $request
            ->withHeaders([
                is_string($headerName) && $headerName !== '' ? $headerName : 'Surrogate-Key' => implode(' ', $normalizedKeys),
            ])
            ->send(in_array($method, ['post', 'put', 'patch', 'delete'], true) ? strtoupper($method) : 'POST', $resolvedEndpoint['url'], [
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

    private function connectTimeoutSeconds(): int
    {
        return min(3, $this->timeoutSeconds());
    }

    /**
     * @return array{url: string, host_header: string, curl_resolve: string}
     */
    private function resolveEndpoint(string $url): array
    {
        $parts = parse_url($url);

        throw_if(! is_array($parts), InvalidArgumentException::class, 'Cache purge endpoint must be an absolute HTTPS URL.');

        $scheme = is_string($parts['scheme'] ?? null) ? strtolower($parts['scheme']) : null;
        $host = is_string($parts['host'] ?? null) ? strtolower($parts['host']) : null;

        throw_if($scheme !== 'https' || $host === null || $host === '' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']), InvalidArgumentException::class, 'Cache purge endpoint must be an absolute HTTPS URL.');

        $port = is_int($parts['port'] ?? null) ? $parts['port'] : 443;

        throw_if($port < 1 || $port > 65535 || ! defined('CURLOPT_RESOLVE'), InvalidArgumentException::class, 'Cache purge endpoint cannot be resolved safely.');

        $addresses = $this->resolvedAddresses($host);

        throw_if($addresses === [] || collect($addresses)->contains(fn (string $address): bool => $this->isUnsafeAddress($address)), InvalidArgumentException::class, 'Cache purge endpoint host is not allowed.');

        return [
            'url' => $url,
            'host_header' => $port === 443 ? $host : $host . ':' . $port,
            'curl_resolve' => sprintf('%s:%d:%s', $host, $port, $addresses[0]),
        ];
    }

    /** @return list<string> */
    private function resolvedAddresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $records = dns_get_record($host, DNS_A | DNS_AAAA);

        if ($records === false) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (array $record): ?string => is_string($record['ip'] ?? null)
                ? $record['ip']
                : (is_string($record['ipv6'] ?? null) ? $record['ipv6'] : null),
            $records,
        ))));
    }

    private function isUnsafeAddress(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }
}
