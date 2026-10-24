<?php

namespace App\Library;

use Illuminate\Validation\ValidationException;

class LibraryPathResolver
{
    public function canonical(string $path, string $field = 'path'): string
    {
        if (! str_starts_with($path, '/') || str_contains($path, "\0")) {
            throw ValidationException::withMessages([$field => 'Enter an absolute folder path.']);
        }

        $canonical = realpath($path);

        if ($canonical === false || ! is_dir($canonical) || ! is_readable($canonical) || ! is_executable($canonical)) {
            throw ValidationException::withMessages([$field => 'The folder must exist and be readable by the application.']);
        }

        return rtrim($canonical, '/') ?: '/';
    }

    public function overlaps(string $first, string $second): bool
    {
        return $first === $second
            || str_starts_with($first, rtrim($second, '/').'/')
            || str_starts_with($second, rtrim($first, '/').'/');
    }
}
