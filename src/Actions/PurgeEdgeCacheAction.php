<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\HtmlCache\Contracts\CachePurger;
use Capell\HtmlCache\Data\EdgeCachePurgeData;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsJob;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

/**
 * @method static void run(EdgeCachePurgeData $purge)
 * @method static mixed dispatch(EdgeCachePurgeData $purge)
 */
final class PurgeEdgeCacheAction
{
    use AsFake;
    use AsJob;
    use AsObject;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [5, 30, 120, 300];

    public static function dispatchAfterCommit(EdgeCachePurgeData $purge): void
    {
        DB::afterCommit(static function () use ($purge): void {
            self::dispatch($purge);
        });
    }

    public function handle(EdgeCachePurgeData $purge): void
    {
        throw_unless(
            resolve(CachePurger::class)->purge($purge),
            RuntimeException::class,
            'Unable to purge the edge HTML cache.',
        );
    }
}
