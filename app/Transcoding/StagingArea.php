<?php

namespace App\Transcoding;

use App\Models\LibraryPath;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class StagingArea
{
    public function disk(): FilesystemAdapter
    {
        $name = (string) config('transcoding.staging_disk');
        if (config('filesystems.disks.'.$name.'.driver') !== 'local') {
            throw new RuntimeException('Transcode staging requires a local filesystem disk.');
        }

        return Storage::disk($name);
    }

    public function directory(int $id, string $attempt): string
    {
        if ($id < 1 || ! Str::isUuid($attempt)) {
            throw new RuntimeException('Invalid transcode attempt identity.');
        }

        return $id.'/'.$attempt;
    }

    public function prepare(int $id, string $attempt): string
    {
        $disk = $this->disk();
        $disk->makeDirectory('');
        $root = realpath($disk->path(''));
        if ($root === false) {
            throw new RuntimeException('The transcode staging directory is unavailable.');
        }
        foreach (LibraryPath::query()->pluck('path') as $libraryPath) {
            $libraryPath = rtrim(realpath($libraryPath) ?: $libraryPath, '/');
            if ($root === $libraryPath || str_starts_with($root.'/', $libraryPath.'/')) {
                throw new RuntimeException('Transcode staging must be outside every library folder.');
            }
        }
        $directory = $this->directory($id, $attempt);
        $disk->makeDirectory($directory);
        $path = $disk->path($directory);
        if (realpath($path) !== $root.'/'.$directory) {
            throw new RuntimeException('The staging attempt directory contains a symbolic link.');
        }

        return $disk->path($directory.'/output.partial.mkv');
    }

    public function accept(int $id, string $attempt): string
    {
        $directory = $this->directory($id, $attempt);
        $disk = $this->disk();
        if (! $disk->exists($directory.'/output.partial.mkv') || $disk->size($directory.'/output.partial.mkv') < 1) {
            throw new RuntimeException('FFmpeg did not produce a nonempty output.');
        }
        if (! $disk->move($directory.'/output.partial.mkv', $directory.'/output.mkv')) {
            throw new RuntimeException('The completed output could not be staged.');
        }

        return $directory.'/output.mkv';
    }

    public function clean(int $id, string $attempt): void
    {
        $disk = $this->disk();
        $directory = $this->directory($id, $attempt);
        if (! $disk->deleteDirectory($directory)) {
            throw new RuntimeException('The incomplete transcode attempt could not be removed.');
        }
    }
}
