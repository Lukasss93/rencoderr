<?php

namespace App\Encoding;

use App\Models\MediaFile;
use InvalidArgumentException;
use RuntimeException;

class ProbeFile
{
    public function path(MediaFile $file): string
    {
        clearstatcache(true);
        $root = $file->libraryPath()->firstOrFail()->path;
        $relative = $file->relative_path;
        if (str_contains($relative, "\0") || str_starts_with($relative, '/') || array_intersect(explode('/', $relative), ['.', '..', '']) !== []) {
            throw new InvalidArgumentException('The media path is not a relative file path.');
        }
        $path = rtrim($root, '/').'/'.$relative;
        $canonicalRoot = realpath($root);
        $canonicalFile = realpath($path);
        if (($canonicalRoot !== false && $canonicalRoot !== $root) || ($canonicalFile !== false && $canonicalFile !== $path)) {
            throw new InvalidArgumentException('The media path now resolves to a different location or contains a symbolic link.');
        }
        if ($canonicalRoot === false || $canonicalFile === false || ! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('The media file is unavailable or unreadable.');
        }

        return $path;
    }

    /** @phpstan-impure */
    public function matches(MediaFile $file, string $path): bool
    {
        clearstatcache(true);
        if (realpath($path) !== $path || ! is_file($path)) {
            return false;
        }
        $stat = stat($path);

        return $stat !== false && $stat['size'] === $file->size_bytes
            && $stat['mtime'] === $file->filesystem_modified_at->timestamp;
    }
}
