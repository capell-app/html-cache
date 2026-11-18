<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Remembers per-site clear decisions for one request or one queued job, so a
 * Pages list asks once per site rather than once per row. It is bound as a
 * scoped instance, which Laravel discards between requests and queued jobs, so
 * a withdrawn permission is never served from an earlier decision.
 */
final class HtmlCacheAuthorizationMemo
{
    /**
     * @var array<string, bool>
     */
    private array $decisions = [];

    /**
     * @param  Closure(): bool  $decide
     */
    public function remember(Authenticatable $actor, int $siteId, Closure $decide): bool
    {
        $identifier = $actor->getAuthIdentifier();

        if (! is_int($identifier) && ! is_string($identifier)) {
            return $decide();
        }

        $key = $actor::class . ':' . $identifier . ':' . $siteId;

        return $this->decisions[$key] ??= $decide();
    }

    public function forget(): void
    {
        $this->decisions = [];
    }
}
