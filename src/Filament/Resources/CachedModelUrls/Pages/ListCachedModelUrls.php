<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Filament\Resources\CachedModelUrls\Pages;

use Capell\HtmlCache\Filament\Resources\CachedModelUrls\CachedModelUrlResource;
use Filament\Resources\Pages\ListRecords;

final class ListCachedModelUrls extends ListRecords
{
    protected static string $resource = CachedModelUrlResource::class;
}
