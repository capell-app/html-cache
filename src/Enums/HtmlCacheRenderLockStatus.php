<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Enums;

enum HtmlCacheRenderLockStatus
{
    case Contended;
    case Unavailable;
}
