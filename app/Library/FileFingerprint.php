<?php

namespace App\Library;

use RuntimeException;

class FileFingerprint
{
    public static function capture(string $path): string
    {
        self::assertCanonical($path);
        $named = @lstat($path);
        if ($named === false || ($named['mode'] & 0170000) !== 0100000 || ! is_readable($path)) {
            throw new RuntimeException('Writeback requires a readable regular file: '.$path);
        }
        $hash = @hash_file('sha256', $path);
        if ($hash === false) {
            throw new RuntimeException('The media file could not be hashed: '.$path);
        }

        return $hash;
    }

    public static function matches(string $path, string $fingerprint): bool
    {
        try {
            return self::capture($path) === $fingerprint;
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
            throw new RuntimeException('Writeback refuses symbolic links and noncanonical paths: '.$path);
        }
    }
}
