<?php

declare(strict_types=1);

use Capell\HtmlCache\Actions\RenderCoalescedHtmlCacheMissAction;
use Capell\HtmlCache\Support\Cache\HtmlCachePublicationGuard;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

uses(HtmlCacheTestCase::class);

beforeEach(function (): void {
    Storage::fake('page_cache');
    File::deleteDirectory(storage_path('framework/cache/html-cache-publication'));
});

afterEach(function (): void {
    File::deleteDirectory(storage_path('framework/cache/html-cache-publication'));
});

function lockFileAgedDays(string $path, int $days): string
{
    File::ensureDirectoryExists(dirname($path));
    touch($path, time() - ($days * 86400));

    return $path;
}

function runScheduledLockFilePrune(): void
{
    $event = collect(resolve(Schedule::class)->events())
        ->first(static fn (ScheduledEvent $event): bool => $event->description === 'capell-html-cache:prune-lock-files');

    throw_unless($event instanceof ScheduledEvent, RuntimeException::class, 'The lock-file prune is not scheduled.');
    $event->run(app());
}

it('prunes lock files nobody used within the retention period and keeps live and recent ones', function (): void {
    $live = resolve(HtmlCachePublicationGuard::class)->lockPath();
    $directory = dirname($live);
    $otherRoot = $directory . '/' . str_repeat('a', 64) . '.lock';
    $files = [
        'live publication lock' => lockFileAgedDays($live, 60),
        'live render slot' => lockFileAgedDays($live . '.render-slot-0', 60),
        'live url counters' => lockFileAgedDays($live . '.urls', 60),
        'old render lock' => lockFileAgedDays($live . '.render-' . hash('sha256', 'https://example.test/old'), 10),
        'recent render lock' => lockFileAgedDays($live . '.render-' . hash('sha256', 'https://example.test/recent'), 1),
        'held render lock' => lockFileAgedDays($live . '.render-' . hash('sha256', 'https://example.test/held'), 10),
        'old root lock' => lockFileAgedDays($otherRoot, 60),
        'old root render lock' => lockFileAgedDays($otherRoot . '.render-' . hash('sha256', 'https://example.test/'), 60),
        'old root render slot' => lockFileAgedDays($otherRoot . '.render-slot-1', 60),
        'old root url counters' => lockFileAgedDays($otherRoot . '.urls', 60),
        'unrelated file' => lockFileAgedDays($directory . '/notes.txt', 60),
    ];
    // A render is holding this lock right now.
    $held = fopen($files['held render lock'], 'c+b');
    throw_if($held === false, RuntimeException::class, 'Unable to open the held render lock.');
    flock($held, LOCK_EX);

    try {
        runScheduledLockFilePrune();
    } finally {
        flock($held, LOCK_UN);
        fclose($held);
    }

    $remaining = array_keys(array_filter($files, static fn (string $path): bool => file_exists($path)));

    expect($remaining)->toBe([
        'live publication lock',
        'live render slot',
        'live url counters',
        'recent render lock',
        'held render lock',
        'unrelated file',
    ]);
});

it('gives one publisher the guard when the prune removes the lock file it was waiting on', function (): void {
    $guard = resolve(HtmlCachePublicationGuard::class);
    $lockPath = $guard->lockPath();
    File::ensureDirectoryExists(dirname($lockPath));
    $ready = $lockPath . '.test-ready';
    // A second process does what the prune does: hold the lock, then unlink it and release it.
    $pruner = proc_open([
        PHP_BINARY,
        '-r',
        '$h = fopen($argv[1], "c+b"); flock($h, LOCK_EX); touch($argv[2]); usleep(1500000); unlink($argv[1]); flock($h, LOCK_UN); fclose($h);',
        $lockPath,
        $ready,
    ], [], $pipes);
    throw_unless(is_resource($pruner), RuntimeException::class, 'Unable to start the pruning process.');

    try {
        $deadline = microtime(true) + 30;

        while (! file_exists($ready) && microtime(true) < $deadline) {
            usleep(10_000);
        }

        throw_unless(file_exists($ready), RuntimeException::class, 'The pruning process never took the lock.');

        $anotherPublisherAcquired = $guard->exclusive(static function () use ($lockPath): bool {
            // A later publisher opens the lock path as it is now.
            $other = fopen($lockPath, 'c+b');
            throw_if($other === false, RuntimeException::class, 'Unable to open the publication lock.');

            try {
                return flock($other, LOCK_EX | LOCK_NB);
            } finally {
                fclose($other);
            }
        });
    } finally {
        proc_close($pruner);
        @unlink($ready);
    }

    expect($anotherPublisherAcquired)->toBeFalse();
});

it('keeps the render lock of a URL rendered since it was created', function (): void {
    $request = Request::create('https://example.test/pricing');
    $lock = lockFileAgedDays(resolve(HtmlCachePublicationGuard::class)->lockPath() . '.render-' . hash('sha256', $request->fullUrl()), 30);

    RenderCoalescedHtmlCacheMissAction::run($request, static fn (): Response => new Response('Rendered pricing'));
    runScheduledLockFilePrune();

    expect(file_exists($lock))->toBeTrue();
});

it('prunes only the live root render locks when the publication lock path is configured into a shared directory', function (): void {
    $directory = storage_path('framework/testing/shared-locks');
    File::deleteDirectory($directory);
    config()->set('capell-html-cache.deployment.publication_lock_path', $directory . '/html-cache.lock');
    $live = resolve(HtmlCachePublicationGuard::class)->lockPath();
    $oldRender = lockFileAgedDays($live . '.render-' . hash('sha256', 'https://example.test/old'), 10);
    $foreignLock = lockFileAgedDays($directory . '/another-application.lock', 60);

    try {
        runScheduledLockFilePrune();

        expect(file_exists($oldRender))->toBeFalse()
            ->and(file_exists($foreignLock))->toBeTrue();
    } finally {
        File::deleteDirectory($directory);
    }
});
