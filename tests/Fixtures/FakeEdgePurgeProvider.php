<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Tests\Fixtures;

use Capell\HtmlCache\Contracts\ReportsEdgePurgeResponses;
use Capell\HtmlCache\Data\EdgeCachePurgeData;
use Capell\HtmlCache\Data\EdgePurgeResponseData;
use Closure;
use Override;

/**
 * An edge provider client that records every purge request and answers
 * from a script, falling back to success once the script runs out.
 */
final class FakeEdgePurgeProvider implements ReportsEdgePurgeResponses
{
    /** @var list<EdgeCachePurgeData> */
    public array $sent = [];

    /** @var list<EdgePurgeResponseData> */
    private array $script = [];

    /** @var (Closure(EdgeCachePurgeData): bool)|null */
    private ?Closure $rejects = null;

    private int $rejectStatus = 400;

    public function __construct(private readonly ?string $zone = 'fake:zone') {}

    /**
     * Refuse, as a non-rate-limit failure, every purge the callback matches.
     *
     * @param  Closure(EdgeCachePurgeData): bool  $rejects
     */
    public function rejectWhen(Closure $rejects, int $status = 400): self
    {
        $this->rejects = $rejects;
        $this->rejectStatus = $status;

        return $this;
    }

    public function answer(EdgePurgeResponseData ...$responses): self
    {
        $this->script = [...$this->script, ...array_values($responses)];

        return $this;
    }

    #[Override]
    public function purge(EdgeCachePurgeData $purge): bool
    {
        return $this->sendPurge($purge)->isSuccessful();
    }

    #[Override]
    public function edgePurgeZone(): ?string
    {
        return $this->zone;
    }

    #[Override]
    public function sendPurge(EdgeCachePurgeData $purge): EdgePurgeResponseData
    {
        $this->sent[] = $purge;

        if ($this->rejects instanceof Closure && ($this->rejects)($purge)) {
            return EdgePurgeResponseData::failed('rejected by the fake provider', $this->rejectStatus);
        }

        return array_shift($this->script) ?? EdgePurgeResponseData::succeeded();
    }

    /** @return list<string> */
    public function sentUrls(): array
    {
        return array_merge(...array_map(static fn (EdgeCachePurgeData $purge): array => $purge->urls, $this->sent));
    }

    /** @return list<string> */
    public function sentTags(): array
    {
        return array_merge(...array_map(static fn (EdgeCachePurgeData $purge): array => $purge->tags, $this->sent));
    }
}
