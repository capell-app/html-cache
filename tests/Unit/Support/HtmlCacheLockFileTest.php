<?php

declare(strict_types=1);

use Capell\HtmlCache\Support\Cache\HtmlCacheLockFile;
use Capell\HtmlCache\Tests\Fixtures\DriftingLockFileStream;

beforeEach(function (): void {
    DriftingLockFileStream::$opens = 0;
    stream_wrapper_register('drifting-lock', DriftingLockFileStream::class);
});

afterEach(function (): void {
    stream_wrapper_unregister('drifting-lock');
});

it('gives up on a lock file that never matches its handle instead of reopening it forever', function (bool $blocking): void {
    // Before the bound, the pruned-lock reopen loop spun here for as long as the identities
    // disagreed, holding a web request or queue worker with it.
    expect(fn (): mixed => HtmlCacheLockFile::lock('drifting-lock://render', $blocking, 'HTML cache render lock'))
        ->toThrow(RuntimeException::class, 'The HTML cache render lock kept changing while it was being locked.');

    expect(DriftingLockFileStream::$opens)->toBe(HtmlCacheLockFile::MAX_OPENS);
})->with(['blocking' => true, 'non-blocking' => false]);

it('locks a real lock file on the first open and reports a held lock without blocking', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'html-cache-lock-');
    throw_unless(is_string($path), RuntimeException::class, 'Unable to create a lock file.');

    try {
        $holder = HtmlCacheLockFile::lock($path, true, 'HTML cache render lock');
        throw_unless(is_resource($holder), RuntimeException::class, 'Expected the first open to hold the lock.');

        expect(HtmlCacheLockFile::lock($path, false, 'HTML cache render lock'))->toBeNull();

        flock($holder, LOCK_UN);
        fclose($holder);
    } finally {
        @unlink($path);
    }
});
