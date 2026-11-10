<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Enums;

enum EdgePurgeOutcome: string
{
    case Succeeded = 'succeeded';

    /** The provider refused the request because of its rate limit. */
    case Throttled = 'throttled';

    case Failed = 'failed';
}
