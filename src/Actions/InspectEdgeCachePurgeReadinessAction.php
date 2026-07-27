<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\HtmlCache\Data\EdgeCachePurgeReadinessData;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class InspectEdgeCachePurgeReadinessAction
{
    use AsFake;
    use AsObject;

    public function handle(?string $expectedDriver = null): EdgeCachePurgeReadinessData
    {
        $configuredDriver = config('capell-html-cache.purge.driver', 'null');
        $driver = is_string($configuredDriver) ? strtolower(trim($configuredDriver)) : '';
        $required = filter_var(
            config('capell-html-cache.purge.required', false),
            FILTER_VALIDATE_BOOLEAN,
        );
        $errors = [];

        if (! in_array($driver, ['null', 'http', 'cloudflare'], true)) {
            $errors[] = sprintf('Unsupported edge purge driver [%s].', $driver);
        }

        if ($expectedDriver !== null && $driver !== strtolower(trim($expectedDriver))) {
            $errors[] = sprintf('Expected edge purge driver [%s], configured [%s].', $expectedDriver, $driver);
        }

        if ($required && $driver === 'null') {
            $errors[] = 'Edge purge is required, but the null driver is configured.';
        }

        if ($driver === 'cloudflare') {
            $this->inspectCloudflareConfiguration($errors);
        }

        if ($driver === 'http') {
            $this->inspectHttpConfiguration($errors);
        }

        return new EdgeCachePurgeReadinessData(
            driver: $driver,
            required: $required,
            errors: array_values(array_unique($errors)),
        );
    }

    /**
     * @param  list<string>  $errors
     */
    private function inspectCloudflareConfiguration(array &$errors): void
    {
        $zoneId = config('capell-html-cache.purge.cloudflare.zone_id');
        $token = config('capell-html-cache.purge.token');

        if (! is_string($zoneId) || preg_match('/^[a-f0-9]{32}$/i', trim($zoneId)) !== 1) {
            $errors[] = 'Cloudflare edge purge requires a 32-character hexadecimal zone ID.';
        }

        if (! is_string($token) || trim($token) === '') {
            $errors[] = 'Cloudflare edge purge requires a non-empty API token.';
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private function inspectHttpConfiguration(array &$errors): void
    {
        $endpoint = config('capell-html-cache.purge.endpoint');
        $token = config('capell-html-cache.purge.token');

        if (! is_string($endpoint)
            || filter_var($endpoint, FILTER_VALIDATE_URL) === false
            || strtolower((string) parse_url($endpoint, PHP_URL_SCHEME)) !== 'https') {
            $errors[] = 'HTTP edge purge requires a valid HTTPS endpoint URL.';
        }

        if (! is_string($token) || trim($token) === '') {
            $errors[] = 'HTTP edge purge requires a non-empty bearer token.';
        }
    }
}
