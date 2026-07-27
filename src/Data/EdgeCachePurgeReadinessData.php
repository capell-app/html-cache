<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Data;

final readonly class EdgeCachePurgeReadinessData
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(
        public string $driver,
        public bool $required,
        public array $errors,
    ) {}

    public function isReady(): bool
    {
        return $this->errors === [];
    }
}
