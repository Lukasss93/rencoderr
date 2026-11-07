<?php

namespace App\Writeback;

use App\Library\FileFingerprint;
use RuntimeException;

class WritebackFilesystem
{
    public function exists(string $path): bool
    {
        clearstatcache(true, $path);

        return @lstat($path) !== false;
    }

    /** @param array<string, int|string>|null $identity */
    public function owns(string $path, ?array $identity): bool
    {
        if ($identity === null) {
            return false;
        }

        try {
            FileFingerprint::assertCanonical($path);
        } catch (RuntimeException) {
            return false;
        }
        $stat = @lstat($path);

        return $stat !== false && ($stat['mode'] & 0170000) === 0100000
            && $stat['dev'] === ($identity['dev'] ?? null) && $stat['ino'] === ($identity['ino'] ?? null);
    }

    public function link(string $from, string $to): void
    {
        FileFingerprint::assertCanonical($from);
        FileFingerprint::assertCanonical($to, false);
        if (! @link($from, $to)) {
            throw new RuntimeException('Cannot publish writeback without overwriting an existing file; the source is preserved.');
        }
        $this->syncDirectory(dirname($to));
    }

    /** @param array<string, int|string> $identity */
    public function remove(string $path, array $identity): void
    {
        if (! $this->owns($path, $identity) || ! @unlink($path)) {
            throw new RuntimeException('Writeback cannot remove a file whose identity changed.');
        }
        $this->syncDirectory(dirname($path));
    }

    public function syncDirectory(string $directory): void
    {
        $stream = @fopen($directory, 'r');
        if ($stream === false) {
            throw new RuntimeException('The writeback directory cannot be opened for synchronization.');
        }
        try {
            if (! fsync($stream)) {
                throw new RuntimeException('The writeback directory could not be synchronized.');
            }
        } finally {
            fclose($stream);
        }
    }
}
