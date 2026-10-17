<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Manifest;

use Capell\Core\Contracts\Extensions\ExtensionContribution;
use Capell\Core\Contracts\Extensions\RegistersExtensionAdminResource;

final class HtmlCacheAdminResourcesContribution implements ExtensionContribution, RegistersExtensionAdminResource
{
    public static function compatibleCapellApiVersion(): string
    {
        return '^1.0';
    }
}
