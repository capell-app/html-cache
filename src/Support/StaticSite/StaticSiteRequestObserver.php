<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\StaticSite;

use Capell\Core\Octane\Resettable;
use Closure;
use Illuminate\Http\Client\Events\ResponseReceived;

final class StaticSiteRequestObserver implements Resettable
{
    private ?string $url = null;

    private ?int $status = null;

    /** @param Closure(): void $visit */
    public function statusFor(string $url, Closure $visit): ?int
    {
        $previousUrl = $this->url;
        $previousStatus = $this->status;
        $this->url = $url;
        $this->status = null;

        try {
            $visit();

            return $this->status;
        } finally {
            $this->url = $previousUrl;
            $this->status = $previousStatus;
        }
    }

    public function record(ResponseReceived $event): void
    {
        if ($event->request->method() === 'GET' && $event->request->url() === $this->url) {
            $this->status = $event->response->status();
        }
    }

    public function flushOctaneState(): void
    {
        $this->url = null;
        $this->status = null;
    }
}
