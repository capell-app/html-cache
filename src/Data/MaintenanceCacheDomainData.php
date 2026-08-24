<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Data;

use Spatie\LaravelData\Data;

final class MaintenanceCacheDomainData extends Data
{
    public function __construct(
        public readonly string $scheme,
        public readonly string $domain,
        public readonly string $path,
        public readonly string $file,
        public readonly ?string $generatedAt = null,
    ) {}

    public function url(): string
    {
        return $this->scheme . '://' . $this->domain . $this->path;
    }
}
