<?php

namespace App\QualityControl;

use App\Library\MediaFilePath;
use App\Models\MediaQualityCheck;
use App\Tasks\Enums\TaskStatus;
use App\Transcoding\StagingArea;
use Illuminate\Support\Str;
use RuntimeException;

class QualityWorkspace
{
    public function __construct(private StagingArea $staging, private MediaFilePath $paths) {}

    /**
     * @return array{source: string, output: string, directory: string, source_identity: array<string, int>, output_identity: array<string, int>}
     */
    public function open(MediaQualityCheck $check): array
    {
        $item = $check->transcode()->firstOrFail();
        $file = $item->mediaFile()->firstOrFail();
        if ($item->status !== TaskStatus::COMPLETED || $item->attempt_token !== $check->transcode_attempt_token
            || $item->output_path !== $check->output_path || $item->output_size_bytes !== $check->output_size_bytes
            || $this->paths->resolve($file) !== $check->source_path) {
            throw new RuntimeException('The accepted encode or source binding changed.');
        }
        $relative = $this->staging->directory($item->id, $check->transcode_attempt_token).'/output.mkv';
        $root = realpath($this->staging->disk()->path(''));
        if ($root === false || $check->output_path !== $relative) {
            throw new RuntimeException('The staged output path does not match the accepted attempt.');
        }
        $output = $root.'/'.$relative;
        $sourceIdentity = $this->identity($check->source_path);
        $outputIdentity = $this->identity($output);
        if ($sourceIdentity['size'] !== $check->source_size_bytes || $sourceIdentity['mtime'] !== $check->source_modified_at->getTimestamp()
            || $outputIdentity['size'] !== $check->output_size_bytes) {
            throw new RuntimeException('The source or staged file changed before quality control.');
        }
        $directory = $this->directory($check);
        $parent = dirname($directory);
        if (is_link($parent) || (! is_dir($parent) && ! mkdir($parent, 0700)) || realpath($parent) !== $parent
            || file_exists($directory) || is_link($directory) || ! mkdir($directory, 0700)) {
            throw new RuntimeException('The quality-control workspace is unavailable or unsafe.');
        }

        return ['source' => $check->source_path, 'output' => $output, 'directory' => $directory,
            'source_identity' => $sourceIdentity, 'output_identity' => $outputIdentity];
    }

    /** @return array<string, int> */
    public function identity(string $path): array
    {
        clearstatcache(true);
        if (! is_file($path) || ! is_readable($path) || is_link($path) || realpath($path) !== $path) {
            throw new RuntimeException('A media file is missing, unreadable or resolves through a symbolic link.');
        }
        $stat = stat($path);
        if ($stat === false || $stat['size'] < 1) {
            throw new RuntimeException('A media file is empty or unavailable.');
        }

        return array_intersect_key($stat, array_flip(['dev', 'ino', 'size', 'mtime', 'ctime']));
    }

    public function clean(MediaQualityCheck $check): void
    {
        if ($check->attempt_token === null || ! is_dir($this->staging->disk()->path(''))) {
            return;
        }
        $directory = $this->directory($check);
        if (! is_dir($directory)) {
            return;
        }
        if (is_link($directory) || realpath($directory) !== $directory) {
            throw new RuntimeException('Refusing to clean a changed quality-control workspace.');
        }
        $relative = $this->staging->directory($check->media_transcode_id, $check->transcode_attempt_token).'/quality-control/'.$check->attempt_token;
        if (! $this->staging->disk()->deleteDirectory($relative)) {
            throw new RuntimeException('Could not clean the quality-control workspace.');
        }
    }

    private function directory(MediaQualityCheck $check): string
    {
        if ($check->attempt_token === null || ! Str::isUuid($check->attempt_token)) {
            throw new RuntimeException('The quality-control attempt identity is invalid.');
        }
        $root = realpath($this->staging->disk()->path(''));
        if ($root === false) {
            throw new RuntimeException('The staging disk is unavailable.');
        }

        return $root.'/'.$this->staging->directory($check->media_transcode_id, $check->transcode_attempt_token).'/quality-control/'.$check->attempt_token;
    }
}
