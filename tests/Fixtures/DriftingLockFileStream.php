<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Tests\Fixtures;

/**
 * A lock file whose path never names the locked handle: every open gets a fresh identity
 * while the path reports another, as when something deletes the file continuously or the
 * filesystem's identities disagree.
 */
final class DriftingLockFileStream
{
    public static int $opens = 0;

    /** @var resource|null */
    public $context;

    /** @var resource */
    private $file;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::$opens++;
        $file = tmpfile();

        if ($file === false) {
            return false;
        }

        $this->file = $file;

        return true;
    }

    public function stream_lock(int $operation): bool
    {
        return flock($this->file, $operation & (LOCK_SH | LOCK_EX | LOCK_UN | LOCK_NB));
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return ['dev' => 1, 'ino' => self::$opens];
    }

    /** @return array<string, int> */
    public function url_stat(string $path, int $flags): array
    {
        return ['dev' => 1, 'ino' => 0];
    }

    public function stream_close(): void
    {
        fclose($this->file);
    }
}
