<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Data;

use Spatie\LaravelData\Data;

final class MaintenancePreparationFailureData extends Data
{
    public function __construct(
        public readonly string $summary,
        public readonly string $recovery,
    ) {}
}
