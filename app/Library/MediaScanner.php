<?php

namespace App\Library;

use App\Library\Enums\MediaFileStatus;
use App\Library\Events\LibraryPathScanned;
use App\Models\LibraryPath;
use App\Models\MediaFile;
use App\Models\MediaProbe;
use App\Tasks\Enums\TaskStatus;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

class MediaScanner
{
    public function __construct(private ExclusionMatcher $exclusions, private LibraryPathResolver $paths) {}

    public function scan(int $pathId, string $token): void
    {
        clearstatcache(true);
        $root = LibraryPath::query()->runningScan($pathId, $token)->first();

        if ($root === null) {
            return;
        }

        if ($this->paths->canonical($root->path) !== $root->path) {
            throw new RuntimeException('The configured folder now resolves to a different location.');
        }

        // A network share that is not mounted leaves an empty folder behind. Scanning it
        // would mark every known video as missing, so treat it as unavailable instead.
        if (File::isEmptyDirectory($root->path) && $root->mediaFiles()->where('status', MediaFileStatus::PRESENT)->exists()) {
            throw new RuntimeException('The folder is empty, but it contained videos at the last scan. If it is a network share, check that it is mounted.');
        }

        $patterns = $root->library()->firstOrFail()->exclusion_patterns;
        $videoExtensions = config()->array('libraries.video_extensions');
        $rows = [];
        $prefixLength = strlen(rtrim($root->path, '/')) + 1;

        foreach ($this->files($root->path, $patterns) as $file) {
            $extension = strtolower($file->getExtension());

            if (! in_array($extension, $videoExtensions, true)) {
                continue;
            }

            $relativePath = substr($file->getPathname(), $prefixLength);
            $size = $file->getSize();
            $modified = $file->getMTime();

            if ($size === false || $modified === false) {
                throw new RuntimeException('Could not read file metadata: '.$file->getPathname());
            }

            $rows[] = [
                'library_path_id' => $pathId,
                'relative_path' => $relativePath,
                'path_hash' => hash('sha256', $relativePath),
                'extension' => $extension,
                'size_bytes' => $size,
                'filesystem_modified_at' => CarbonImmutable::createFromTimestampUTC($modified)->toDateTimeString(),
                'first_seen_at' => now()->toDateTimeString(),
                'last_seen_at' => now()->toDateTimeString(),
                'last_seen_scan_token' => $token,
                'status' => MediaFileStatus::PRESENT->value,
            ];

            if (count($rows) === 200) {
                if (! $this->write($pathId, $token, $rows)) {
                    return;
                }

                $rows = [];
            }
        }

        if ($rows !== [] && ! $this->write($pathId, $token, $rows)) {
            return;
        }

        $this->reconcile($pathId, $token, $patterns);

        $completed = LibraryPath::query()->runningScan($pathId, $token)->update([
            'scan_status' => TaskStatus::COMPLETED,
            'scan_finished_at' => now(),
            'last_successful_scan_at' => now(),
            'scan_error' => null,
        ]);

        if ($completed === 1) {
            LibraryPathScanned::dispatch($pathId, $token);
        }
    }

    /**
     * @param  list<string>  $patterns
     * @return Generator<int, SplFileInfo>
     */
    protected function files(string $root, array $patterns): Generator
    {
        $prefixLength = strlen(rtrim($root, '/')) + 1;
        $iterator = new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS);
        $filtered = new RecursiveCallbackFilterIterator($iterator, function (SplFileInfo $entry) use ($patterns, $prefixLength): bool {
            if ($entry->isLink()) {
                return false;
            }

            $relativePath = substr($entry->getPathname(), $prefixLength);

            if ($this->exclusions->matches($relativePath, $patterns, $entry->isDir())) {
                return false;
            }

            if ($entry->isDir() && (! $entry->isReadable() || ! is_executable($entry->getPathname()))) {
                throw new RuntimeException('Could not read directory: '.$entry->getPathname());
            }

            return $entry->isDir() || $entry->isFile();
        });

        foreach (new RecursiveIteratorIterator($filtered) as $entry) {
            if ($entry instanceof SplFileInfo && $entry->isFile()) {
                yield $entry;
            }
        }
    }

    /** @param list<array<string, int|string>> $rows */
    private function write(int $pathId, string $token, array $rows): bool
    {
        return DB::transaction(function () use ($pathId, $token, $rows): bool {
            if (LibraryPath::query()->runningScan($pathId, $token)->lockForUpdate()->first() === null) {
                return false;
            }

            $hashes = array_column($rows, 'path_hash');
            $restoredIds = array_values(MediaFile::query()->where('library_path_id', $pathId)->whereIn('path_hash', $hashes)
                ->where('status', '!=', MediaFileStatus::PRESENT)->get()->map(fn (MediaFile $file): int => $file->id)->all());

            MediaFile::query()->upsert($rows, ['library_path_id', 'path_hash'], [
                'relative_path', 'extension', 'size_bytes', 'filesystem_modified_at',
                'last_seen_at', 'last_seen_scan_token', 'status',
            ]);
            $this->resetChangedProbes(MediaFile::query()->where('library_path_id', $pathId)->whereIn('path_hash', $hashes)->get(), $restoredIds);

            return true;
        });
    }

    /**
     * @param  Collection<int, MediaFile>  $files
     * @param  list<int>  $restoredIds
     */
    private function resetChangedProbes(Collection $files, array $restoredIds): void
    {
        $probes = MediaProbe::query()->whereIn('media_file_id', $files->modelKeys())->get()->keyBy('media_file_id');
        $rows = [];

        foreach ($files as $file) {
            $probe = $probes->get($file->id);

            if ($probe !== null && $probe->matches($file) && ! in_array($file->id, $restoredIds, true)) {
                continue;
            }

            $rows[] = [
                'media_file_id' => $file->id,
                'source_size_bytes' => $file->size_bytes,
                'source_modified_at' => $file->filesystem_modified_at->toDateTimeString(),
                ...MediaProbe::pendingAttributes(),
            ];
        }

        if ($rows !== []) {
            MediaProbe::query()->upsert($rows, ['media_file_id'], ['source_size_bytes', 'source_modified_at', ...array_keys(MediaProbe::pendingAttributes())]);
        }
    }

    /** @param list<string> $patterns */
    private function reconcile(int $pathId, string $token, array $patterns): void
    {
        MediaFile::query()->where('library_path_id', $pathId)
            ->where(function (Builder $query) use ($token): void {
                $query->whereNull('last_seen_scan_token')->orWhere('last_seen_scan_token', '!=', $token);
            })
            ->chunkById(200, function (Collection $files) use ($pathId, $token, $patterns): bool {
                return DB::transaction(function () use ($files, $pathId, $token, $patterns): bool {
                    if (LibraryPath::query()->runningScan($pathId, $token)->lockForUpdate()->first() === null) {
                        return false;
                    }

                    $missing = [];
                    $excluded = [];

                    foreach ($files as $file) {
                        if ($this->exclusions->matches($file->relative_path, $patterns)) {
                            $excluded[] = $file->id;
                        } else {
                            $missing[] = $file->id;
                        }
                    }

                    MediaProbe::query()->whereIn('media_file_id', $files->modelKeys())->update(MediaProbe::pendingAttributes());

                    MediaFile::query()->where('library_path_id', $pathId)->whereIn('id', $missing)
                        ->update(['status' => MediaFileStatus::MISSING]);
                    MediaFile::query()->where('library_path_id', $pathId)->whereIn('id', $excluded)
                        ->update(['status' => MediaFileStatus::EXCLUDED]);

                    return true;
                });
            });
    }
}
