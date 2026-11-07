<?php

namespace App\Library;

use RuntimeException;

class FileFingerprint
{
    /** @return array{dev:int, ino:int, size:int, mtime:int, ctime:int, sha256:string} */
    public static function capture(string $path): array
    {
        self::assertCanonical($path);
        $named = @lstat($path);
        if ($named === false || ($named['mode'] & 0170000) !== 0100000 || ! is_readable($path)) {
            throw new RuntimeException('Writeback requires a readable regular file.');
        }
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('The media file cannot be opened.');
        }

        try {
            $before = fstat($stream);
            if ($before === false || ($before['mode'] & 0170000) !== 0100000
                || $before['dev'] !== $named['dev'] || $before['ino'] !== $named['ino']) {
                throw new RuntimeException('Writeback requires a readable regular file.');
            }
            $hash = hash_init('sha256');
            if (hash_update_stream($hash, $stream) !== $before['size']) {
                throw new RuntimeException('The media file could not be read completely.');
            }
            $after = fstat($stream);
            clearstatcache(true, $path);
            $named = @lstat($path);
            foreach (['dev', 'ino', 'size', 'mtime', 'ctime'] as $key) {
                if ($after === false || $named === false || $before[$key] !== $after[$key] || $before[$key] !== $named[$key]) {
                    throw new RuntimeException('The media file changed while being fingerprinted.');
                }
            }

            return ['dev' => $before['dev'], 'ino' => $before['ino'], 'size' => $before['size'],
                'mtime' => $before['mtime'], 'ctime' => $before['ctime'], 'sha256' => hash_final($hash)];
        } finally {
            fclose($stream);
        }
    }

    /** @param array<string, int|string> $fingerprint */
    public static function matches(string $path, array $fingerprint, bool $sameFile = true): bool
    {
        try {
            $actual = self::capture($path);
            foreach ($sameFile ? ['dev', 'ino', 'size', 'mtime', 'ctime', 'sha256'] : ['size', 'sha256'] as $key) {
                if (! array_key_exists($key, $fingerprint) || $actual[$key] !== $fingerprint[$key]) {
                    return false;
                }
            }

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public static function assertCanonical(string $path, bool $mustExist = true): void
    {
        clearstatcache(true, $path);
        if (! str_starts_with($path, '/') || str_contains($path, "\0")
            || realpath(dirname($path)) !== dirname($path)
            || in_array(basename($path), ['.', '..', ''], true)
            || is_link($path) || ($mustExist && realpath($path) !== $path)) {
            throw new RuntimeException('Writeback refuses symbolic links and noncanonical paths.');
        }
    }
}
