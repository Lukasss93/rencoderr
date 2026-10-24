<?php

namespace App\Transcoding\Actions;

use App\Jobs\TranscodeMediaFile;
use App\Models\MediaTranscode;
use App\Models\TranscodeRun;
use App\Tasks\Enums\TaskStatus;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class DispatchTranscode
{
    public function __construct(private Dispatcher $bus, private UniqueLock $locks) {}

    public function handle(int $transcodeId): bool
    {
        return DB::transaction(function () use ($transcodeId): bool {
            $runId = MediaTranscode::query()->whereKey($transcodeId)->value('transcode_run_id');
            $run = TranscodeRun::query()->whereKey($runId)->lockForUpdate()->first();
            $item = MediaTranscode::query()->lockForUpdate()->find($transcodeId);
            if ($item === null || $run === null || $run->cancel_requested_at !== null
                || $run->finished_at !== null || $item->status !== TaskStatus::PENDING) {
                return false;
            }
            $job = $this->job($item);
            if (! $this->locks->acquire($job)) {
                return false;
            }
            DB::afterRollBack(fn () => $this->locks->release($job));
            $item->update([
                'status' => TaskStatus::QUEUED, 'unique_lock_owner' => $job->uniqueLockOwner,
                'dispatch_intended_at' => now(), 'dispatched_at' => null,
            ]);
            DB::afterCommit(fn () => $this->publish($job));

            return true;
        });
    }

    public function replay(MediaTranscode $item): void
    {
        if ($item->status === TaskStatus::QUEUED && $item->dispatched_at === null && $item->unique_lock_owner !== null) {
            $this->publish($this->job($item));
        }
    }

    public function job(MediaTranscode $item): TranscodeMediaFile
    {
        $job = new TranscodeMediaFile($item->id, $item->file_identity_id, $item->request_token, $item->execution_limit_seconds);
        $job->uniqueLockOwner = $item->unique_lock_owner ?? '';

        return $job;
    }

    public function release(MediaTranscode $item): void
    {
        if ($item->unique_lock_owner !== null) {
            $this->locks->release($this->job($item));
        }
    }

    private function publish(TranscodeMediaFile $job): void
    {
        try {
            $this->bus->dispatch($job);
            MediaTranscode::query()->forRequest($job->transcodeId, $job->requestToken)
                ->where('status', TaskStatus::QUEUED)->where('unique_lock_owner', $job->uniqueLockOwner)
                ->update(['dispatched_at' => now()]);
        } catch (Throwable $exception) {
            try {
                MediaTranscode::query()->forRequest($job->transcodeId, $job->requestToken)
                    ->where('status', TaskStatus::QUEUED)->where('unique_lock_owner', $job->uniqueLockOwner)
                    ->update(['status' => TaskStatus::PENDING, 'unique_lock_owner' => null,
                        'dispatch_intended_at' => null, 'dispatched_at' => null, 'error' => Str::limit($exception->getMessage(), 4000)]);
            } finally {
                $this->locks->release($job);
            }
            throw $exception;
        }
    }
}
