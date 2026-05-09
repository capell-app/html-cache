<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Bridges;

use Capell\Admin\Contracts\Bridges\AdminBridge;
use Capell\Admin\Data\Bridges\AdminBridgeContextData;
use Capell\Admin\Support\Bridges\AdminBridgeRegistrar;
use Capell\HtmlCache\Filament\Resources\CachedModelUrls\CachedModelUrlResource;

final class HtmlCacheAdminBridge implements AdminBridge
{
    public function isEnabled(AdminBridgeContextData $context): bool
    {
        return true;
    }

    public function register(AdminBridgeRegistrar $registrar, AdminBridgeContextData $context): void
    {
        $registrar->resource(CachedModelUrlResource::class, group: 'HtmlCache');
    }
}
