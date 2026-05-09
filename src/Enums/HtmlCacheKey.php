<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Enums;

enum HtmlCacheKey: string
{
    case GeneratingStaticSite = 'generating-static-site';
}
