<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Exceptions;

use Capell\HtmlCache\Enums\HtmlCacheEligibilityReason;
use RuntimeException;

final class StaleCachedUrlNotApplicableException extends RuntimeException
{
    public function __construct(
        public readonly HtmlCacheEligibilityReason $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
