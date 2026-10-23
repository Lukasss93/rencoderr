<?php

namespace App\Scan;

use App\Encoding\Actions\RequestMediaProbe;
use App\Models\LibraryPath;
use App\Models\MediaFile;
use App\Models\MediaProbe;
use App\Scan\Enums\MediaFileStatus;
use App\Scan\Enums\ScanStatus;
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
    public function __construct(private ExclusionMatcher $exclusions, private LibraryPathResolver $paths, private RequestMediaProbe $probes) {}

    public function scan(int $pathId, string $token): void
    {
        clearstatcache(true);
        $root = $this->currentRoot($pathId, $token)->first();

        if ($root === null) {
            return;
        }

        if ($this->paths->canonical($root->path) !== $root->path) {
            throw new RuntimeException('The configured folder now resolves to a different location.');
        }

        // A network share that is not mounted leaves an empty folder behind. Scanning it
        // would mark every known video as missing, so treat it as unavailable instead.
        if (File::isEmptyDirectory($root->path) && $root->mediaFiles()->where('status', MediaFileStatus::Present)->exists()) {
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
                'status' => MediaFileStatus::Present->value,
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
        $completed = $this->currentRoot($pathId, $token)->update([
            'scan_status' => ScanStatus::Completed,
            'scan_finished_at' => now(),
            'last_successful_scan_at' => now(),
            'scan_error' => null,
        ]);
        if ($completed === 1) {
            $this->probes->forPath($pathId, $token);
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

    /**
     * @param  list<array<string, int|string>>  $rows
     */
    private function write(int $pathId, string $token, array $rows): bool
    {
        return DB::transaction(function () use ($pathId, $token, $rows): bool {
            if ($this->currentRoot($pathId, $token)->lockForUpdate()->first() === null) {
                return false;
            }

            $hashes = array_column($rows, 'path_hash');
            $restoredIds = array_values(MediaFile::query()->where('library_path_id', $pathId)->whereIn('path_hash', $hashes)
                ->where('status', '!=', MediaFileStatus::Present)->get()->map(fn (MediaFile $file): int => $file->id)->all());

            MediaFile::query()->upsert($rows, ['library_path_id', 'path_hash'], [
                'relative_path', 'extension', 'size_bytes', 'filesystem_modified_at',
                'last_seen_at', 'last_seen_scan_token', 'status',
            ]);
            $this->probes->sync(MediaFile::query()->where('library_path_id', $pathId)->whereIn('path_hash', $hashes)->get(), $restoredIds);

            return true;
        });
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
                    if ($this->currentRoot($pathId, $token)->lockForUpdate()->first() === null) {
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

                    MediaProbe::query()->whereIn('media_file_id', $files->modelKeys())->update(RequestMediaProbe::pendingAttributes());

                    MediaFile::query()->where('library_path_id', $pathId)->whereIn('id', $missing)
                        ->update(['status' => MediaFileStatus::Missing]);
                    MediaFile::query()->where('library_path_id', $pathId)->whereIn('id', $excluded)
                        ->update(['status' => MediaFileStatus::Excluded]);

                    return true;
                });
            });
    }

    /** @return Builder<LibraryPath> */
    private function currentRoot(int $pathId, string $token): Builder
    {
        return LibraryPath::query()->whereKey($pathId)->where('scan_token', $token)
            ->where('scan_status', ScanStatus::Scanning);
    }
}
