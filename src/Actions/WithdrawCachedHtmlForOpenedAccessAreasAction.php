<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\Core\Support\TableName;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * An active Access Gate area with a future opens_at does not gate yet, so public
 * pages are cached and the web server serves them straight from disk. Reaching
 * opens_at fires no model event, so this sweep withdraws every cached copy and
 * the edge copies at that moment.
 *
 * The area records the opens_at value it has withdrawn for in
 * cache_withdrawn_for_opens_at, so each opening is withdrawn once and a
 * rescheduled opens_at is withdrawn again. Saving an area also withdraws via the
 * model observer; the sweep may then withdraw once more, which is harmless.
 */
final class WithdrawCachedHtmlForOpenedAccessAreasAction
{
    use AsFake;
    use AsObject;

    private const string MARKER = 'cache_withdrawn_for_opens_at';

    public function handle(): int
    {
        $table = TableName::resolve('access_gate_areas');
        $connection = config('access-gate.connection');
        $connection = is_string($connection) && $connection !== '' ? $connection : null;

        if ($connection === null && ! resolve(RuntimeSchemaState::class)->hasTable($table)) {
            return 0;
        }

        if (! DB::connection($connection)->getSchemaBuilder()->hasColumn($table, self::MARKER)) {
            return 0;
        }

        // Area schedules are stored as UTC instants.
        $now = now()->utc()->format('Y-m-d H:i:s');

        $due = DB::connection($connection)->table($table)
            ->where('status', 'active')
            ->whereNotNull('opens_at')
            ->where('opens_at', '<=', $now)
            ->where(function ($query) use ($now): void {
                $query->whereNull('closes_at')->orWhere('closes_at', '>', $now);
            })
            ->where(function ($query): void {
                $query->whereNull(self::MARKER)->orWhereColumn(self::MARKER, '!=', 'opens_at');
            })
            ->get(['id', 'opens_at']);

        if ($due->isEmpty()) {
            return 0;
        }

        ClearAllHtmlCacheAction::run(awaitEdgePurge: true);

        foreach ($due as $area) {
            // Matching opens_at keeps an opens_at rescheduled during the clear due.
            DB::connection($connection)->table($table)
                ->where('id', $area->id)
                ->where('opens_at', $area->opens_at)
                ->update([self::MARKER => $area->opens_at]);
        }

        return $due->count();
    }
}
