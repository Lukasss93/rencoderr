<?php

namespace App\Writeback\Actions;

use App\Library\FileFingerprint;
use App\Library\LibraryPathLock;
use App\Models\LibraryPath;
use App\Models\MediaWriteback;
use App\QualityControl\Enums\QualityVerdict;
use App\Tasks\Enums\TaskStatus;
use App\Transcoding\OutputContainer;
use App\Transcoding\StagingArea;
use App\Writeback\Enums\WritebackMode;
use App\Writeback\Events\WritebackInstalled;
use App\Writeback\WritebackFilesystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class RunWriteback
{
    public function __construct(private WritebackFilesystem $files, private StagingArea $staging) {}

    public function handle(int $id, string $requestToken): void
    {
        $receipt = MediaWriteback::query()->whereKey($id)->where('request_token', $requestToken)
            ->where('status', TaskStatus::QUEUED)->first();
        if ($receipt === null) {
            return;
        }

        Cache::lock('writeback-source:'.hash('sha256', $receipt->source_path), (int) config('writeback.timeout_seconds', 21600) + 60)
            ->get(function () use ($receipt, $requestToken): void {
                $pathId = $receipt->transcode()->firstOrFail()->mediaFile?->library_path_id;
                if ($pathId === null) {
                    MediaWriteback::query()->whereKey($receipt->id)->where('request_token', $requestToken)
                        ->where('status', TaskStatus::QUEUED)->update([
                            'status' => TaskStatus::FAILED, 'finished_at' => now(),
                            'error' => 'The library file was removed before writeback could start.',
                        ]);

                    return;
                }
                LibraryPathLock::forPath($pathId, (int) config('writeback.timeout_seconds', 21600) + 60)
                    ->get(function () use ($receipt, $requestToken): void {
                        $attempt = (string) Str::uuid();
                        $claimed = MediaWriteback::query()->whereKey($receipt->id)->where('request_token', $requestToken)
                            ->where('status', TaskStatus::QUEUED)->update([
                                'status' => TaskStatus::RUNNING, 'attempt_token' => $attempt, 'started_at' => now(), 'error' => null,
                            ]);
                        if ($claimed !== 1) {
                            return;
                        }
                        $receipt->refresh();

                        try {
                            $this->run($receipt);
                        } catch (Throwable $exception) {
                            $this->restoreOriginal($receipt);
                            $this->current($receipt)->update([
                                'status' => TaskStatus::FAILED, 'error' => Str::limit($exception->getMessage(), 4000), 'finished_at' => now(),
                            ]);
                        }
                    });
            });
    }

    private function run(MediaWriteback $receipt): void
    {
        $this->validatePaths($receipt);
        $check = $receipt->qualityCheck()->firstOrFail();
        $transcode = $receipt->transcode()->firstOrFail();
        if ($check->status !== TaskStatus::COMPLETED || $check->verdict !== QualityVerdict::PASSED
            || $check->media_transcode_id !== $receipt->media_transcode_id
            || $transcode->status !== TaskStatus::COMPLETED
            || $check->transcode_attempt_token !== $transcode->attempt_token) {
            throw new RuntimeException('Writeback requires the matching completed, passing quality check.');
        }

        if ($receipt->phase !== 'replacing' && $receipt->phase !== 'installed') {
            $this->requireMatch($receipt->source_path, $receipt->source_fingerprint);
            $this->requireMatch($receipt->staging_path, $receipt->output_fingerprint);
            $this->copyCandidate($receipt);
            $this->requireMatch($receipt->source_path, $receipt->source_fingerprint);
            $this->requireMatch($receipt->staging_path, $receipt->output_fingerprint);

            if ($receipt->mode === WritebackMode::APPROVAL && $receipt->approved_at === null) {
                $this->save($receipt, ['status' => TaskStatus::PENDING, 'phase' => 'awaiting_approval', 'finished_at' => now()]);

                return;
            }
        }

        if ($receipt->mode === WritebackMode::APPROVAL && $receipt->approved_at === null) {
            throw new RuntimeException('Replacing the source requires explicit approval.');
        }
        if ($receipt->phase !== 'replacing' && $receipt->phase !== 'installed'
            && ($this->files->exists($receipt->backup_path)
                || ($receipt->destination_path !== $receipt->source_path && $this->files->exists($receipt->destination_path)))) {
            throw new RuntimeException('The writeback backup or destination already exists.');
        }
        $path = LibraryPath::query()->whereKey($receipt->transcode()->firstOrFail()->mediaFile()->firstOrFail()->library_path_id)->firstOrFail();
        if ($path->scan_status === TaskStatus::RUNNING) {
            $this->save($receipt, ['status' => TaskStatus::QUEUED, 'attempt_token' => null]);

            return;
        }
        $this->save($receipt, ['phase' => 'replacing']);
        $this->install($receipt);
        rescue(fn () => event(new WritebackInstalled($receipt->id)));
    }

    private function validatePaths(MediaWriteback $receipt): void
    {
        $source = $receipt->source_path;
        $directory = dirname($source);
        FileFingerprint::assertCanonical($source, false);
        $root = $receipt->transcode()->firstOrFail()->mediaFile()->firstOrFail()->libraryPath()->firstOrFail()->path;
        if (realpath($root) !== $root || ! str_starts_with($source, rtrim($root, '/').'/')) {
            throw new RuntimeException('The writeback source is outside its library folder.');
        }
        foreach ([$receipt->candidate_path, $receipt->destination_path, $receipt->backup_path] as $path) {
            FileFingerprint::assertCanonical($path, false);
            if (dirname($path) !== $directory || $path === $receipt->staging_path) {
                throw new RuntimeException('Writeback artifacts must remain beside the original source.');
            }
        }
        if ($receipt->copy_path !== null) {
            FileFingerprint::assertCanonical($receipt->copy_path, false);
            if (preg_match('/^'.preg_quote($receipt->candidate_path, '/').'\.copy-[0-9a-f-]{36}\.part$/D', $receipt->copy_path) !== 1) {
                throw new RuntimeException('The temporary copy path does not match its writeback candidate.');
            }
        }
        $transcode = $receipt->transcode()->firstOrFail();
        $check = $receipt->qualityCheck()->firstOrFail();
        $container = OutputContainer::tryFrom(pathinfo($receipt->destination_path, PATHINFO_EXTENSION));
        $expected = $container === null ? null
            : $this->staging->directory($transcode->id, $check->transcode_attempt_token).'/'.$container->filename();
        $staging = $expected === null ? null : $this->staging->root().'/'.$expected;
        $file = $transcode->mediaFile()->firstOrFail();
        if ($expected === null || ! in_array($receipt->staging_path, [$staging, $this->staging->disk()->path($expected)], true)
            || $check->output_path !== $expected || $transcode->output_path !== $expected
            || $receipt->source_path !== $check->source_path || $receipt->source_path !== $transcode->source_path
            || $source !== rtrim($root, '/').'/'.$file->relative_path
            || $receipt->source_fingerprint !== $check->source_fingerprint || $receipt->output_fingerprint !== $check->output_fingerprint) {
            throw new RuntimeException('The writeback paths and fingerprints no longer match the verified transcode attempt.');
        }
        $extension = pathinfo($receipt->destination_path, PATHINFO_EXTENSION);
        $stem = pathinfo($source, PATHINFO_FILENAME);
        if (! in_array($extension, ['mkv', 'mp4'], true)
            || $receipt->destination_path !== $directory.'/'.$stem.'.'.$extension
            || preg_match('/^'.preg_quote($stem, '/').'\.rencoderr-[0-9a-f-]{36}\.pending\.'.preg_quote($extension, '/').'$/D', basename($receipt->candidate_path)) !== 1
            || preg_match('/^'.preg_quote($source, '/').'\.rencoderr-[0-9a-f-]{36}\.backup$/D', $receipt->backup_path) !== 1) {
            throw new RuntimeException('Writeback artifact paths do not match the source and selected container.');
        }
        if ($receipt->staging_path !== $staging) {
            $this->save($receipt, ['staging_path' => $staging]);
        }
    }

    private function copyCandidate(MediaWriteback $receipt): void
    {
        if ($this->files->exists($receipt->candidate_path)) {
            $this->requireMatch($receipt->candidate_path, $receipt->output_fingerprint);
            $this->save($receipt, ['phase' => 'copied']);
            $this->cleanCopy($receipt);

            return;
        }
        if ($receipt->phase !== null && $receipt->phase !== 'copying') {
            throw new RuntimeException('The verified writeback candidate disappeared.');
        }

        $previousCopy = $receipt->copy_path;
        if ($previousCopy !== null && $this->files->exists($previousCopy)) {
            if (FileFingerprint::matches($previousCopy, $receipt->output_fingerprint)) {
                $this->promoteCopy($receipt);

                return;
            }
            $this->assertCurrent($receipt);
            $this->files->remove($previousCopy);
        }

        $copy = $receipt->candidate_path.'.copy-'.$receipt->attempt_token.'.part';
        $this->save($receipt, ['phase' => 'copying', 'copy_path' => $copy]);
        $input = @fopen($receipt->staging_path, 'rb');
        $output = @fopen($copy, 'x+b');
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            throw new RuntimeException('The writeback temporary copy cannot be created exclusively.');
        }
        try {
            $this->files->syncDirectory(dirname($copy));
            if (stream_copy_to_stream($input, $output) === false) {
                throw new RuntimeException('The writeback copy was incomplete.');
            }
            $permissions = fileperms($receipt->source_path);
            if ($permissions === false || ! chmod($copy, $permissions & 0777)
                || ! fflush($output) || ! fsync($output)) {
                throw new RuntimeException('The writeback copy could not be flushed with the original permissions.');
            }
        } finally {
            fclose($input);
            fclose($output);
        }
        $this->promoteCopy($receipt);
    }

    private function promoteCopy(MediaWriteback $receipt): void
    {
        $copy = $receipt->copy_path;
        if ($copy === null) {
            throw new RuntimeException('The temporary copy path is missing.');
        }
        $this->requireMatch($copy, $receipt->output_fingerprint);
        $stream = @fopen($copy, 'rb');
        if ($stream === false) {
            throw new RuntimeException('The verified temporary copy cannot be reopened.');
        }
        try {
            $permissions = fileperms($receipt->source_path);
            if ($permissions === false || ! chmod($copy, $permissions & 0777) || ! fsync($stream)) {
                throw new RuntimeException('The verified temporary copy could not be synchronized with the original permissions.');
            }
        } finally {
            fclose($stream);
        }
        $this->assertCurrent($receipt);
        $this->files->link($copy, $receipt->candidate_path);
        $this->requireMatch($receipt->candidate_path, $receipt->output_fingerprint);
        $this->save($receipt, ['phase' => 'copied']);
        $this->cleanCopy($receipt);
    }

    private function cleanCopy(MediaWriteback $receipt): void
    {
        $copy = $receipt->copy_path;
        if ($copy !== null && $this->files->exists($copy)) {
            $this->assertCurrent($receipt);
            $this->files->remove($copy);
        }
        $this->save($receipt, ['copy_path' => null]);
    }

    private function install(MediaWriteback $receipt): void
    {
        $source = $receipt->source_path;
        $backup = $receipt->backup_path;
        $destination = $receipt->destination_path;
        $hasBackup = $this->files->exists($backup);
        $published = $this->files->exists($destination) && FileFingerprint::matches($destination, $receipt->output_fingerprint);

        if ($hasBackup) {
            $this->requireMatch($backup, $receipt->source_fingerprint);
        }
        if ($published) {
            if ($receipt->phase !== 'replacing' && $receipt->phase !== 'installed') {
                throw new RuntimeException('The destination was installed outside the replacement journal.');
            }
        } else {
            $this->requireMatch($receipt->staging_path, $receipt->output_fingerprint);
            $this->requireMatch($receipt->candidate_path, $receipt->output_fingerprint);
            if ($this->files->exists($destination) && $destination !== $source) {
                throw new RuntimeException('The writeback destination already exists.');
            }
            if (! $hasBackup) {
                $this->requireMatch($source, $receipt->source_fingerprint);
                $this->files->link($source, $backup);
                $hasBackup = true;
            }
            if ($destination === $source && $this->files->exists($source)) {
                $this->assertCurrent($receipt);
                $this->files->remove($source, $receipt->source_fingerprint);
            }
            $this->assertCurrent($receipt);
            $this->files->link($receipt->candidate_path, $destination);
            $this->requireMatch($destination, $receipt->output_fingerprint);
        }

        $this->save($receipt, ['phase' => 'installed', 'installed_at' => now()]);
        if ($destination !== $source && $this->files->exists($source)) {
            $this->assertCurrent($receipt);
            $this->files->remove($source, $receipt->source_fingerprint);
        }
        if ($this->files->exists($receipt->candidate_path)) {
            $this->assertCurrent($receipt);
            $this->files->remove($receipt->candidate_path, $receipt->output_fingerprint);
        }
        $this->requireMatch($destination, $receipt->output_fingerprint);
        if ($hasBackup) {
            $this->assertCurrent($receipt);
            $this->files->remove($backup, $receipt->source_fingerprint);
        }
        $this->save($receipt, ['status' => TaskStatus::COMPLETED, 'finished_at' => now()]);
    }

    private function restoreOriginal(MediaWriteback $receipt): void
    {
        try {
            if ($this->current($receipt)->exists() && ! $this->files->exists($receipt->source_path)
                && $this->files->exists($receipt->backup_path)) {
                $this->requireMatch($receipt->backup_path, $receipt->source_fingerprint);
                $this->files->link($receipt->backup_path, $receipt->source_path);
            }
        } catch (Throwable) {
            // Keep the original backup intact when restoration is unavailable.
        }
    }

    private function requireMatch(string $path, string $fingerprint): void
    {
        if (FileFingerprint::capture($path) !== $fingerprint) {
            throw new RuntimeException('A writeback file checksum does not match: '.$path);
        }
    }

    /** @return Builder<MediaWriteback> */
    private function current(MediaWriteback $receipt): Builder
    {
        return MediaWriteback::query()->whereKey($receipt->id)->where('request_token', $receipt->request_token)
            ->where('attempt_token', $receipt->attempt_token)->where('status', TaskStatus::RUNNING);
    }

    private function assertCurrent(MediaWriteback $receipt): void
    {
        if (! $this->current($receipt)->exists()) {
            throw new RuntimeException('The writeback attempt is no longer current.');
        }
    }

    /** @param array<string, mixed> $attributes */
    private function save(MediaWriteback $receipt, array $attributes): void
    {
        if ($this->current($receipt)->update($attributes) !== 1) {
            throw new RuntimeException('The writeback attempt is no longer current.');
        }
        $receipt->fill($attributes);
    }
}
