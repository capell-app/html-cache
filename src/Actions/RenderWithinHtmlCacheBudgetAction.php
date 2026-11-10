<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Actions;

use Capell\HtmlCache\Enums\HtmlCacheRenderLockStatus;
use Capell\HtmlCache\Support\Cache\HtmlCachePublicationGuard;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Bounds how many uncached HTML renders run at once across every URL, so a
 * burst of distinct cold misses cannot occupy the whole PHP-FPM pool.
 *
 * Slots are flock()ed files: a crashed or killed worker releases its slot
 * when the process exits, so no slot can leak.
 *
 * @method static Response|HtmlCacheRenderLockStatus run(Closure $render)
 */
final class RenderWithinHtmlCacheBudgetAction
{
    use AsFake;
    use AsObject;

    private const int POLL_MILLISECONDS = 100;

    /** @param Closure(): Response $render */
    public function handle(Closure $render): Response|HtmlCacheRenderLockStatus
    {
        if (config('capell-html-cache.render_budget.enabled', true) !== true) {
            return $render();
        }

        try {
            $slot = $this->acquireSlot();
        } catch (Throwable $throwable) {
            // Never turn a lock-file fault into a visitor outage.
            report($throwable);

            return $render();
        }

        if ($slot === null) {
            return HtmlCacheRenderLockStatus::Contended;
        }

        try {
            return $render();
        } finally {
            flock($slot, LOCK_UN);
            fclose($slot);
        }
    }

    /** @return resource|null */
    private function acquireSlot(): mixed
    {
        $prefix = resolve(HtmlCachePublicationGuard::class)->lockPath() . '.render-slot-';
        $slots = $this->positiveConfigInteger('capell-html-cache.render_budget.max_concurrent', 4);
        $deadline = Carbon::now()->addSeconds($this->nonNegativeConfigInteger('capell-html-cache.render_budget.wait_seconds', 2));

        while (true) {
            for ($slot = 0; $slot < $slots; $slot++) {
                // capell-security-scan: local-stream reads a render budget slot lock file in application storage
                $handle = fopen($prefix . $slot, 'c+b');

                if ($handle === false) {
                    throw new RuntimeException('Unable to open an HTML cache render slot.');
                }

                if (flock($handle, LOCK_EX | LOCK_NB)) {
                    return $handle;
                }

                fclose($handle);
            }

            if (Carbon::now()->greaterThanOrEqualTo($deadline)) {
                return null;
            }

            Sleep::for(self::POLL_MILLISECONDS)->milliseconds();
        }
    }

    private function positiveConfigInteger(string $key, int $default): int
    {
        return max(1, $this->nonNegativeConfigInteger($key, $default));
    }

    private function nonNegativeConfigInteger(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_numeric($value) ? max(0, (int) $value) : $default;
    }
}
