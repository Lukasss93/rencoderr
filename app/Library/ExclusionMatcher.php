<?php

namespace App\Library;

use Illuminate\Support\Str;

class ExclusionMatcher
{
    /** @param list<string> $patterns */
    public function matches(string $relativePath, array $patterns, bool $directory = false): bool
    {
        $parts = explode('/', $relativePath);
        $candidates = [$relativePath, basename($relativePath)];
        $prefix = '';

        $directories = $directory ? $parts : array_slice($parts, 0, -1);

        foreach ($directories as $part) {
            $prefix = $prefix === '' ? $part : $prefix.'/'.$part;
            $candidates[] = $part;
            $candidates[] = $part.'/';
            $candidates[] = $prefix;
            $candidates[] = $prefix.'/';
        }

        foreach ($patterns as $pattern) {
            foreach ($candidates as $candidate) {
                if (Str::is($pattern, $candidate)) {
                    return true;
                }
            }
        }

        return false;
    }
}
