<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Manifest;

use Capell\Core\Contracts\Extensions\ExtensionContribution;

final class HtmlCacheAdminPagesContribution implements ExtensionContribution
{
    public static function compatibleCapellApiVersion(): string
    {
        return '^0.0';
    }
}
