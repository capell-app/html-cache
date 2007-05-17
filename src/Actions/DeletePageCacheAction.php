<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\PageUrl;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsJob;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

/**
 * @method static bool run(Pageable|PageUrl $record, ?bool $refresh = null)
 */
final class DeletePageCacheAction
{
    use AsFake;
    use AsJob;
    use AsObject;

    public function handle(Pageable|PageUrl $record, ?bool $refresh = null): bool
    {
        $refresh ??= config('capell-admin.auto_refresh_cache');

        $pageUrls = $record instanceof Pageable ? $record->pageUrls : collect([$record]);

        foreach ($pageUrls as $pageUrl) {
            if ($pageUrl instanceof PageUrl) {
                if (! ClearCachedUrlAction::run($pageUrl->full_url, refresh: $refresh)) {
                    throw new RuntimeException(sprintf('Unable to clear HTML cache for "%s".', $pageUrl->full_url));
                }
            }
        }

        return true;
    }
}
