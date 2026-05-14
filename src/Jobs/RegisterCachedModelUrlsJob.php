<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Jobs;

use Capell\HtmlCache\Actions\RecordCachedModelUrlsAction;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class RegisterCachedModelUrlsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    /**
     * @param  array<string, array<int, int|string>>  $models
     */
    public function __construct(
        private readonly string $url,
        private readonly array $models,
        private readonly ?CarbonInterface $seenAt = null,
    ) {}

    public function handle(): void
    {
        RecordCachedModelUrlsAction::run($this->url, $this->models, $this->seenAt ?? CarbonImmutable::now());
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [1, 5, 15, 30];
    }
}
