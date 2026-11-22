<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\HtmlCache\Enums\HtmlCacheRenderLockStatus;
use Capell\HtmlCache\Support\Cache\HtmlCacheLockFile;
use Capell\HtmlCache\Support\Cache\HtmlCachePublicationGuard;
use Closure;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
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
            $handle = $this->acquire($path);
        } catch (Throwable $throwable) {
            report($throwable);

            return HtmlCacheRenderLockStatus::Unavailable;
        }

        if ($handle === null) {
            return HtmlCacheRenderLockStatus::Contended;
        }

        // Unlike the cache lease, this lock survives slow renders and releases on process
        // exit. Contenders must share one inode: only the unused-lock prune unlinks it, and a
        // holder confirms its handle is still the file at the path.
        try {
            return $render();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return resource|null Null when another render holds the lock. */
    private function acquire(string $path): mixed
    {
        $handle = HtmlCacheLockFile::lock($path, false, 'HTML cache render lock');

        if ($handle !== null) {
            // Records the last use, so the lock-file prune keeps locks of URLs still rendered.
            // Only a hint: a failed touch must not make the lock unavailable.
            @touch($path);
        }

        return $handle;
    }
}
