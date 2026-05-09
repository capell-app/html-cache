<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Enums;

enum HtmlCachePermission: string
{
    case ViewCachedModelUrls = 'capell-html-cache.view';
    case ClearCachedModelUrls = 'capell-html-cache.clear';

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(
            fn (self $permission): string => $permission->value,
            self::cases(),
        );
    }
}
