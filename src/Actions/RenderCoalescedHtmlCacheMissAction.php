<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\HtmlCache\Enums\HtmlCacheRenderLockStatus;
use Capell\HtmlCache\Support\Cache\HtmlCachePublicationGuard;
use Closure;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * @method static Response|HtmlCacheRenderLockStatus run(Request $request, Closure $render)
 */
final class RenderCoalescedHtmlCacheMissAction
{
    use AsFake;
    use AsObject;

    /** @param Closure(): Response $render */
    public function handle(Request $request, Closure $render): Response|HtmlCacheRenderLockStatus
    {
        try {
            $path = resolve(HtmlCachePublicationGuard::class)->lockPath()
                . '.render-' . hash('sha256', $request->fullUrl());
            $handle = fopen($path, 'c+b');

            if ($handle === false) {
                throw new RuntimeException('Unable to open the HTML cache render lock.');
            }
        } catch (Throwable $throwable) {
            report($throwable);

            return HtmlCacheRenderLockStatus::Unavailable;
        }

        // Unlike the cache lease, this lock survives slow renders and releases
        // on process exit. Never unlink it: contenders must share the same inode.
        try {
            try {
                if (! flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
                    if ($wouldBlock === 1) {
                        return HtmlCacheRenderLockStatus::Contended;
                    }

                    throw new RuntimeException('Unable to acquire the HTML cache render lock.');
                }
            } catch (Throwable $throwable) {
                report($throwable);

                return HtmlCacheRenderLockStatus::Unavailable;
            }

            return $render();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
