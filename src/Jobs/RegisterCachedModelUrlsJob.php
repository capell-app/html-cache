<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Jobs;

use Capell\HtmlCache\Actions\RecordCachedModelUrlsAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class RegisterCachedModelUrlsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, array<int, int|string>>  $models
     */
    public function __construct(
        private readonly string $url,
        private readonly array $models,
    ) {}

    public function uniqueId(): string
    {
        return $this->url;
    }

    public function handle(): void
    {
        RecordCachedModelUrlsAction::run($this->url, $this->models);
    }
}
