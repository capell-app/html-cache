<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Data;

final readonly class EdgePurgeDrainResultData
{
    /**
     * @param  int  $sentBatches  provider requests that succeeded during this run
     * @param  int|null  $releaseAfterSeconds  set when the drain must run again after this delay
     */
    public function __construct(
        public int $sentBatches = 0,
        public ?int $releaseAfterSeconds = null,
    ) {}

    public function finished(): bool
    {
        return $this->releaseAfterSeconds === null;
    }
}
