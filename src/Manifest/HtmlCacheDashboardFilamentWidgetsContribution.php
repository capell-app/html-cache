<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Manifest;

use Capell\Core\Contracts\Extensions\ExtensionContribution;
use Capell\Core\Contracts\Extensions\RegistersExtensionFilamentWidget;

final class HtmlCacheDashboardFilamentWidgetsContribution implements ExtensionContribution, RegistersExtensionFilamentWidget
{
    public static function compatibleCapellApiVersion(): string
    {
        return '^4.0';
    }
}
