<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Frontend\Support\Cache\SurrogateKeyNormalizer;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** @method static list<string> run(Request $request) */
final class ResolveEdgeCacheTagsAction
{
    use AsFake;
    use AsObject;

    /** @return list<string> */
    public function handle(Request $request): array
    {
        $host = strtolower($request->getHost());

        return $host === ''
            ? []
            : array_values(SurrogateKeyNormalizer::normalize(['host-' . str_replace('.', '-', $host)]));
    }
}
