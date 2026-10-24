<?php

namespace App\Probe\Actions;

use App\Jobs\ProbeMediaFile;
use App\Library\Enums\MediaFileStatus;
use App\Models\LibraryPath;
use App\Models\MediaFile;
use App\Models\MediaProbe;
use App\Tasks\Enums\TaskStatus;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class RequestProbe
{
    public function __construct(private Dispatcher $bus) {}

    public function afterScan(int $fileId, string $scanToken): bool
    {
        return DB::transaction(function () use ($fileId, $scanToken): bool {
            $locked = $this->lock($fileId);

            if ($locked === null) {
                return false;
            }

            [$file, $root, $probe] = $locked;

            if ($probe?->status !== TaskStatus::PENDING || ! $this->seenByCompletedScan($file, $root, $scanToken)) {
                return false;
            }

            $job = $this->queue($file, $probe);
            DB::afterCommit(fn () => rescue(fn () => $this->dispatch($job)));

            return true;
        });
    }

    public function retry(int $fileId): bool
    {
        return DB::transaction(function () use ($fileId): bool {
            $locked = $this->lock($fileId);

            if ($locked === null) {
                return false;
            }

            [$file, , $probe] = $locked;
            $probe ??= $file->probe()->create([
                'source_size_bytes' => $file->size_bytes,
                'source_modified_at' => $file->filesystem_modified_at,
                ...MediaProbe::pendingAttributes(),
            ]);

            if ($probe->status->isActive()) {
                return false;
            }

            $probe->attempts = 0;
            $job = $this->queue($file, $probe);
            DB::afterCommit(fn () => rescue(fn () => $this->dispatch($job)));

            return true;
        });
    }

    /**
     * Only call during exclusive startup, before workers or requests start. A failed dispatch is
     * thrown so startup stops.
     */
    public function recover(int $fileId): bool
    {
        return DB::transaction(function () use ($fileId): bool {
            $locked = $this->lock($fileId);

            if ($locked === null) {
                return false;
            }

            [$file, $root, $probe] = $locked;

            if ($probe === null || ! ($probe->status->isActive() || $probe->status === TaskStatus::PENDING)) {
                return false;
            }

            // A pending probe that was never requested waits for a completed scan of its folder.
            if ($probe->status === TaskStatus::PENDING && $probe->request_token === null
                && ! $this->seenByCompletedScan($file, $root, $root->scan_token)) {
                return false;
            }

            if ($probe->attempts >= ProbeMediaFile::MAX_ATTEMPTS) {
                $probe->update(['status' => TaskStatus::FAILED, 'finished_at' => now(), 'error' => 'The probe exhausted its attempts before completion.']);

                return false;
            }

            $job = $this->queue($file, $probe);
            DB::afterCommit(fn () => $this->dispatch($job));

            return true;
        });
    }

    /** @return array{MediaFile, LibraryPath, ?MediaProbe}|null */
    private function lock(int $fileId): ?array
    {
        $rootId = MediaFile::query()->whereKey($fileId)->value('library_path_id');
        $root = LibraryPath::query()->whereKey($rootId)->lockForUpdate()->first();
        $file = MediaFile::query()->whereKey($fileId)->lockForUpdate()->first();

        if ($root === null || $file === null || $file->status !== MediaFileStatus::PRESENT) {
            return null;
        }

        return [$file, $root, $file->probe()->lockForUpdate()->first()];
    }

    private function seenByCompletedScan(MediaFile $file, LibraryPath $root, ?string $scanToken): bool
    {
        return $root->scan_status === TaskStatus::COMPLETED
            && $root->scan_token === $scanToken
            && $file->last_seen_scan_token === $scanToken;
    }

    private function queue(MediaFile $file, MediaProbe $probe): ProbeMediaFile
    {
        $token = (string) Str::uuid();
        $probe->update([
            'status' => TaskStatus::QUEUED, 'request_token' => $token, 'attempt_token' => null,
            'source_size_bytes' => $file->size_bytes, 'source_modified_at' => $file->filesystem_modified_at,
            'requested_at' => now(), 'started_at' => null, 'finished_at' => null, 'error' => null,
        ]);

        return new ProbeMediaFile($file->id, $token, $probe->attempts);
    }

    /**
     * A failed dispatch returns the probe to pending and keeps its request token, so recovery retries it.
     */
    private function dispatch(ProbeMediaFile $job): void
    {
        try {
            $this->bus->dispatch($job);
        } catch (Throwable $exception) {
            MediaProbe::query()->forRequest($job->fileId, $job->token)->where('status', TaskStatus::QUEUED)
                ->update(['status' => TaskStatus::PENDING, 'error' => $exception->getMessage()]);

            throw $exception;
        }
    }
}
