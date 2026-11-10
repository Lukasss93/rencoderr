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

    public function link(string $from, string $to): void
    {
        FileFingerprint::assertCanonical($from);
        FileFingerprint::assertCanonical($to, false);
        if (! @link($from, $to)) {
            throw new RuntimeException('Cannot publish writeback without overwriting an existing file; the source is preserved.');
        }
        $this->syncDirectory(dirname($to));
    }

    public function remove(string $path, ?string $fingerprint = null): void
    {
        FileFingerprint::assertCanonical($path);
        if ($fingerprint !== null && ! FileFingerprint::matches($path, $fingerprint)) {
            throw new RuntimeException('Writeback cannot remove a file whose contents changed.');
        }
        if (! @unlink($path)) {
            throw new RuntimeException('The writeback file could not be removed.');
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
