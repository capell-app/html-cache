<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\Core\Actions\SiteDomains\ResolveSiteDomainAction;
use Capell\Core\Data\SiteDomains\SiteRequestTargetData;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** @method static PageUrl|null run(Request $request) */
final class ResolveHtmlCachePageUrlAction
{
    use AsFake;
    use AsObject;

    public function handle(Request $request): ?PageUrl
    {
        try {
            $target = SiteRequestTargetData::fromUrl($request->getUri());
        } catch (InvalidArgumentException) {
            return null;
        }

        $resolved = ResolveSiteDomainAction::run($target, Site::query()->excludingPreview()->with('siteDomains')->get());
        $domain = $resolved?->siteDomainForRequest();
        if (! $domain instanceof SiteDomain || ! $domain->status) {
            return null;
        }

        $pageUrl = PageUrl::query()->where('site_id', $domain->site_id)
            ->where('language_id', $domain->language_id)
            ->where('url', '/' . trim($resolved->relativePath, '/'))->enabled()->first();
        $pageUrl?->setRelation('siteDomain', $domain);

        return $pageUrl;
    }
}
