<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\HtmlCache\Models\CachedModelUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * @method static int run(Request $request, int $bytesServed)
 */
final class RecordHtmlCacheHitAction
{
    use AsAction;

    public function handle(Request $request, int $bytesServed): int
    {
        if ($bytesServed < 0) {
            $bytesServed = 0;
        }

        return CachedModelUrl::query()
            ->where('url_hash', CachedModelUrl::hashUrl($request->fullUrl()))
            ->update([
                'hit_count' => DB::raw('hit_count + 1'),
                'bytes_served' => DB::raw('bytes_served + ' . $bytesServed),
                'last_hit_at' => now(),
            ]);
    }
}
