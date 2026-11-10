<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Jobs;

use Capell\HtmlCache\Actions\RecordCachedModelUrlsAction;
use Capell\HtmlCache\Actions\WithdrawCachedUrlsAction;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

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

    /**
     * The page is already cached without its dependency index, so a later
     * withdrawal could not find it. Withdraw it instead; the next request
     * renders and records it again.
     */
    public static function withdrawUnrecordedUrl(string $url): void
    {
        try {
            WithdrawCachedUrlsAction::run([$url]);
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }

    public function handle(): void
    {
        RecordCachedModelUrlsAction::run($this->url, $this->models, $this->seenAt ?? CarbonImmutable::now());
    }

    public function failed(?Throwable $exception = null): void
    {
        self::withdrawUnrecordedUrl($this->url);
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [1, 5, 15, 30];
    }
}
