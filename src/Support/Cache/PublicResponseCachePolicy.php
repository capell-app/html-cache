<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\Cache;

use Capell\HtmlCache\Enums\HtmlCacheEligibilityReason;
use Symfony\Component\HttpFoundation\Response;

final class PublicResponseCachePolicy
{
    /** @return list<HtmlCacheEligibilityReason> */
    public function reasons(Response $response): array
    {
        $reasons = [];
        $frameworkDefault = $this->hasFrameworkDefaultCacheControl($response);

        if (! $frameworkDefault && $response->headers->hasCacheControlDirective('private')) {
            $reasons[] = HtmlCacheEligibilityReason::ResponsePrivate;
        }

        if (! $frameworkDefault && $response->headers->hasCacheControlDirective('no-store')) {
            $reasons[] = HtmlCacheEligibilityReason::ResponseNoStore;
        }

        if (! $frameworkDefault && $response->headers->hasCacheControlDirective('no-cache')) {
            $reasons[] = HtmlCacheEligibilityReason::ResponseNoCache;
        }

        if ($response->headers->getCookies() !== []) {
            $reasons[] = HtmlCacheEligibilityReason::ResponseSetsCookie;
        }

        if (! $this->hasSupportedVaryHeader($response)) {
            $reasons[] = HtmlCacheEligibilityReason::UnsupportedVaryHeader;
        }

        return $reasons;
    }

    public function isCacheable(Response $response): bool
    {
        return $this->reasons($response) === [];
    }

    private function hasFrameworkDefaultCacheControl(Response $response): bool
    {
        $directives = array_map(
            static fn (string $directive): string => trim(strtolower($directive)),
            explode(',', (string) $response->headers->get('Cache-Control')),
        );
        sort($directives);

        return in_array($directives, [
            ['no-cache', 'private'],
            ['max-age=0', 'must-revalidate', 'no-cache', 'no-store', 'private'],
        ], true) && $response->headers->getCookies() === [];
    }

    private function hasSupportedVaryHeader(Response $response): bool
    {
        $vary = $response->headers->all('Vary');

        if ($vary === []) {
            return true;
        }

        $configuredHeaders = config('capell-html-cache.cache_vary_headers', ['Accept-Encoding']);
        $supportedHeaders = is_array($configuredHeaders)
            ? array_values(array_map(
                static fn (string $header): string => strtolower(trim($header)),
                array_filter($configuredHeaders, is_string(...)),
            ))
            : [];

        foreach ($vary as $line) {
            if (! is_string($line)) {
                return false;
            }

            foreach (explode(',', $line) as $header) {
                if (! in_array(strtolower(trim($header)), $supportedHeaders, true)) {
                    return false;
                }
            }
        }

        return true;
    }
}
