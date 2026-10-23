<?php

namespace App\Encoding\Actions;

use App\Encoding\Enums\ProbeStatus;
use App\Jobs\ProbeMediaFile;
use App\Models\LibraryPath;
use App\Models\MediaFile;
use App\Models\MediaProbe;
use App\Scan\Enums\MediaFileStatus;
use App\Scan\Enums\ScanStatus;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class RequestMediaProbe
{
    public function __construct(private Dispatcher $bus) {}

    /**
     * Called inside the scanner transaction, including before a scan has completed.
     *
     * @param  Collection<int, MediaFile>  $files
     * @param  list<int>  $restoredIds
     */
    public function sync(Collection $files, array $restoredIds): void
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
                ...self::pendingAttributes(),
            ];
        }
        if ($rows !== []) {
            MediaProbe::query()->upsert($rows, ['media_file_id'], [
                'source_size_bytes', 'source_modified_at', ...array_keys(self::pendingAttributes()),
            ]);
        }
    }

    /** @return array<string, int|string|null> */
    public static function pendingAttributes(): array
    {
        return [
            'status' => ProbeStatus::Pending->value, 'request_token' => null, 'attempt_token' => null,
            'attempts' => 0, 'requested_at' => null, 'started_at' => null, 'finished_at' => null, 'error' => null,
        ];
    }

    public function forPath(int $pathId, string $scanToken): int
    {
        $queued = 0;
        foreach (MediaFile::query()->where('library_path_id', $pathId)->where('last_seen_scan_token', $scanToken)
            ->where('status', MediaFileStatus::Present)
            ->whereHas('probe', fn (Builder $query) => $query->where('status', ProbeStatus::Pending))
            ->lazyById(200) as $file) {
            $queued += (int) $this->queue($file->id, false, false, $scanToken);
        }

        return $queued;
    }

    public function handle(int $fileId, bool $force = false): bool
    {
        return $this->queue($fileId, $force, false);
    }

    /** Only call during exclusive startup, before workers or requests start. */
    public function recover(int $fileId): bool
    {
        return $this->queue($fileId, false, true);
    }

    private function queue(int $fileId, bool $force, bool $recover, ?string $scanToken = null): bool
    {
        return DB::transaction(function () use ($fileId, $force, $recover, $scanToken): bool {
            $rootId = MediaFile::query()->whereKey($fileId)->value('library_path_id');
            $root = LibraryPath::query()->whereKey($rootId)->lockForUpdate()->first();
            $file = MediaFile::query()->whereKey($fileId)->lockForUpdate()->first();
            if ($root === null || $file === null || $file->status !== MediaFileStatus::Present) {
                return false;
            }
            if ($scanToken !== null && ($root->scan_status !== ScanStatus::Completed || $root->scan_token !== $scanToken || $file->last_seen_scan_token !== $scanToken)) {
                return false;
            }
            $probe = $file->probe()->lockForUpdate()->first();
            if ($probe === null) {
                if (! $force) {
                    return false;
                }
                $probe = $file->probe()->create([
                    'source_size_bytes' => $file->size_bytes,
                    'source_modified_at' => $file->filesystem_modified_at,
                    ...self::pendingAttributes(),
                ]);
            }
            $active = in_array($probe->status, [ProbeStatus::Queued, ProbeStatus::Probing], true);
            if ((! $recover && $active) || (! $force && ! $recover && $probe->status !== ProbeStatus::Pending)) {
                return false;
            }
            if ($recover) {
                if (! $active && $probe->status !== ProbeStatus::Pending) {
                    return false;
                }
                if (! $active && $probe->request_token === null
                    && ($root->scan_status !== ScanStatus::Completed || $file->last_seen_scan_token !== $root->scan_token)) {
                    return false;
                }
                if ($probe->attempts >= ProbeMediaFile::MAX_ATTEMPTS) {
                    $probe->update(['status' => ProbeStatus::Failed, 'finished_at' => now(), 'error' => 'The probe exhausted its attempts before completion.']);

                    return false;
                }
            }
            $token = (string) Str::uuid();
            $probe->update([
                'status' => ProbeStatus::Queued, 'request_token' => $token, 'attempt_token' => null,
                'source_size_bytes' => $file->size_bytes, 'source_modified_at' => $file->filesystem_modified_at,
                'attempts' => $force ? 0 : $probe->attempts, 'requested_at' => now(), 'started_at' => null,
                'finished_at' => null, 'error' => null,
            ]);
            $job = new ProbeMediaFile($fileId, $token, $probe->attempts);
            DB::afterCommit(fn () => $this->dispatch($job, $recover));

            return true;
        });
    }

    private function dispatch(ProbeMediaFile $job, bool $recover): void
    {
        try {
            $this->bus->dispatch($job);
        } catch (Throwable $exception) {
            MediaProbe::query()->where('media_file_id', $job->fileId)->where('request_token', $job->token)
                ->where('status', ProbeStatus::Queued)
                ->update(['status' => ProbeStatus::Pending, 'error' => $exception->getMessage()]);
            if ($recover) {
                throw $exception;
            }
            report($exception);
        }
    }
}
