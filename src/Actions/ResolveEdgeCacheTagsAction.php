<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Frontend\Support\Cache\SurrogateKeyNormalizer;
use Capell\HtmlCache\Support\Cache\HtmlCachePublicationGuard;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** @method static list<string> run(Request $request) */
final class ResolveEdgeCacheTagsAction
{
    use AsFake;
    use AsObject;

    /**
     * The tag shared by every query variant of one host and path, so clearing
     * a URL also purges its ?page=2 copies without purging the rest of the site.
     */
    public static function urlTag(string $url): string
    {
        return self::urlTagForRequest(Request::create($url));
    }

    public static function urlTagForRequest(Request $request): string
    {
        return 'url-' . substr(HtmlCachePublicationGuard::urlKey($request), 0, 32);
    }

    /** @return list<string> */
    public function handle(Request $request): array
    {
        $host = strtolower($request->getHost());

        return $host === ''
            ? []
            : array_values(SurrogateKeyNormalizer::normalize([
                'host-' . str_replace('.', '-', $host),
                self::urlTagForRequest($request),
            ]));
    }
}
