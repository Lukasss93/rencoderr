<?php

namespace App\Library\Actions;

use App\Library\LibraryPathResolver;
use App\Models\Library;
use App\Models\LibraryPath;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveLibrary
{
    public function __construct(private LibraryPathResolver $paths, private RequestLibraryScan $requestScan) {}

    /**
     * @param  array{name: string, automatic_scanning: bool, scan_interval_minutes: int, exclusion_patterns: list<string>}  $attributes
     * @param  list<string>  $paths
     */
    public function handle(?int $libraryId, array $attributes, array $paths): Library
    {
        $retained = $libraryId === null ? [] : LibraryPath::query()
            ->where('library_id', $libraryId)->pluck('path')->all();
        $canonical = [];

        foreach ($paths as $index => $path) {
            $path = trim($path);
            $canonical[] = in_array($path, $retained, true)
                ? $path
                : $this->paths->canonical($path, 'paths.'.$index);
        }

        // The lock stops two saves from adding overlapping folders at the same time.
        try {
            $library = Cache::lock('library-path-management', 30)->block(5, fn (): Library => DB::transaction(
                fn (): Library => $this->persist($libraryId, $attributes, $canonical),
            ));
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['paths' => 'Another library is being saved. Try again in a moment.']);
        }

        if ($library->wasChanged('exclusion_patterns')) {
            $this->requestScan->handle($library);
        } else {
            $this->requestScan->forNewPaths($library);
        }

        return $library;
    }

    /**
     * @param  array{name: string, automatic_scanning: bool, scan_interval_minutes: int, exclusion_patterns: list<string>}  $attributes
     * @param  list<string>  $canonical
     */
    private function persist(?int $libraryId, array $attributes, array $canonical): Library
    {
        $library = $libraryId === null ? new Library : Library::query()->findOrFail($libraryId);
        $others = LibraryPath::query()
            ->when($libraryId !== null, fn ($query) => $query->where('library_id', '!=', $libraryId))
            ->pluck('path')->all();
        $accepted = [];

        foreach ($canonical as $index => $path) {
            foreach ([...$others, ...$accepted] as $existing) {
                if ($this->paths->overlaps($path, $existing)) {
                    throw ValidationException::withMessages([
                        'paths.'.$index => 'This folder duplicates or overlaps another configured folder: '.$existing,
                    ]);
                }
            }

            $accepted[] = $path;
        }

        $library->fill($attributes)->save();
        $library->paths()->whereNotIn('path', $canonical)->delete();

        foreach ($canonical as $path) {
            $library->paths()->firstOrCreate(
                ['path_hash' => hash('sha256', $path)],
                ['path' => $path],
            );
        }

        return $library;
    }
}
