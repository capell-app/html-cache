<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Data;

final readonly class HtmlCacheClearResult
{
    /**
     * @param  array<int, string>  $deletedDirectories
     * @param  array<int, string>  $deletedFiles
     * @param  array<int, string>  $failedDirectories
     * @param  array<int, string>  $failedFiles
     */
    public function __construct(
        public array $deletedDirectories = [],
        public array $deletedFiles = [],
        public array $failedDirectories = [],
        public array $failedFiles = [],
    ) {}

    public function successful(): bool
    {
        return $this->failedDirectories === [] && $this->failedFiles === [];
    }

    public function deletedCount(): int
    {
        return count($this->deletedDirectories) + count($this->deletedFiles);
    }

    /** @return array<int, string> */
    public function failures(): array
    {
        return [
            ...$this->failedDirectories,
            ...$this->failedFiles,
        ];
    }
}
