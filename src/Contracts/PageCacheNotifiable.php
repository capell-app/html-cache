<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Contracts;

use Illuminate\Database\Eloquent\Model;

interface PageCacheNotifiable
{
    public function notifyPageCached(Model $model): void;
}
