<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\PageUrl;
use Lorisleiva\Actions\Concerns\AsAction;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static bool run(Pageable|PageUrl $record, ?bool $refresh = null)
 */
final class DeletePageCacheAction
{
    use AsAction;
    use AsFake;
    use AsObject;

    public function handle(Pageable|PageUrl $record, ?bool $refresh = null): bool
    {
        $refresh ??= config('capell-admin.auto_refresh_cache');

        $pageUrls = $record instanceof Pageable ? $record->pageUrls : collect([$record]);

        foreach ($pageUrls as $pageUrl) {
            if ($pageUrl instanceof PageUrl) {
                ClearCachedUrlAction::run($pageUrl->full_url, refresh: (bool) $refresh);
            }
        }

        return true;
    }
}
