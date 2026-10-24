<?php

namespace App\Library;

use DirectoryIterator;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

class DirectoryBrowser
{
    public function __construct(private LibraryPathResolver $paths) {}

    /** @return array{path: string, parent: ?string, breadcrumbs: list<array{name: string, path: string}>, directories: list<array{name: string, path: string}>, total: int, page: int, last_page: int} */
    public function browse(string $path, int $page = 1): array
    {
        $path = $this->paths->canonical($path, 'browserPath');
        $directories = [];

        try {
            foreach (new DirectoryIterator($path) as $entry) {
                if (! $entry->isDot() && $entry->isDir() && $entry->isReadable() && is_executable($entry->getPathname())) {
                    $directories[] = ['name' => $entry->getFilename(), 'path' => $entry->getPathname()];
                }
            }
        } catch (UnexpectedValueException) {
            throw ValidationException::withMessages(['browserPath' => 'This folder could not be listed.']);
        }

        usort($directories, fn (array $a, array $b): int => strnatcmp($a['name'], $b['name']));
        $lastPage = max(1, (int) ceil(count($directories) / 50));
        $page = max(1, min($page, $lastPage));
        $breadcrumbs = [['name' => '/', 'path' => '/']];
        $prefix = '';

        foreach (array_filter(explode('/', $path)) as $part) {
            $prefix .= '/'.$part;
            $breadcrumbs[] = ['name' => $part, 'path' => $prefix];
        }

        return [
            'path' => $path,
            'parent' => $path === '/' ? null : dirname($path),
            'breadcrumbs' => $breadcrumbs,
            'directories' => array_slice($directories, ($page - 1) * 50, 50),
            'total' => count($directories),
            'page' => $page,
            'last_page' => $lastPage,
        ];
    }
}
