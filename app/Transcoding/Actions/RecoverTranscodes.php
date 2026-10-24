<?php

namespace App\Transcoding\Actions;

use App\Jobs\QueueLibraryTranscodes;
use App\Models\MediaTranscode;
use App\Models\TranscodeRun;
use App\Tasks\Enums\TaskStatus;
use App\Transcoding\StagingArea;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RecoverTranscodes
{
    public function __construct(
        private DispatchTranscode $dispatch,
        private CancelTranscodeRun $cancel,
        private FinalizeTranscodeRun $finalize,
        private StagingArea $staging,
        private Dispatcher $bus,
    ) {}

    /** Exclusive recovery requires all previous workers and encoder processes to have stopped. */
    public function handle(bool $exclusive = false): int
    {
        $recovered = 0;
        foreach (TranscodeRun::query()->active()->lazyById(200) as $run) {
            if ($run->library_id === null || $run->cancel_requested_at !== null) {
                $this->cancel->handle($run);
            } elseif ($run->enumeration_finished_at === null) {
                if ($exclusive) {
                    $job = new QueueLibraryTranscodes($run->id, $run->request_token);
                    Cache::lock($job->middleware()[0]->getLockKey($job))->forceRelease();
                    $run->update(['request_token' => (string) Str::uuid()]);
                }
                $this->bus->dispatch(new QueueLibraryTranscodes($run->id, $run->request_token));
            }
        }
        foreach (MediaTranscode::query()->active()->lazyById(200) as $item) {
            if ($exclusive || ($item->status === TaskStatus::RUNNING && $item->execution_deadline_at?->lte(now()->subSeconds(120)))) {
                if (! $this->reset($item, $exclusive)) {
                    continue;
                }
                $item->refresh();
            }
            if ($item->status === TaskStatus::PENDING) {
                $recovered += (int) $this->dispatch->handle($item->id);
            } elseif ($item->status === TaskStatus::QUEUED && $item->dispatched_at === null) {
                $this->dispatch->replay($item);
                $recovered++;
            }
        }
        foreach (TranscodeRun::query()->active()->lazyById(200) as $run) {
            $this->finalize->handle($run->id);
        }

        return $recovered;
    }

    private function reset(MediaTranscode $item, bool $exclusive): bool
    {
        $job = $this->dispatch->job($item);
        $key = $job->middleware()[0]->getLockKey($job);
        if ($exclusive) {
            Cache::lock($key)->forceRelease();
        }
        $lock = Cache::lock($key, 120);
        if (! $lock->get()) {
            return false;
        }
        try {
            return DB::transaction(function () use ($item, $exclusive): bool {
                $run = TranscodeRun::query()->whereKey($item->transcode_run_id)->lockForUpdate()->firstOrFail();
                $owned = MediaTranscode::query()->forRequest($item->id, $item->request_token)->active()->lockForUpdate()->first();
                if ($owned === null || (! $exclusive && ($owned->status !== TaskStatus::RUNNING || $owned->execution_deadline_at === null || $owned->execution_deadline_at->gt(now()->subSeconds(120))))) {
                    return false;
                }
                if ($owned->attempt_token !== null) {
                    $this->staging->clean($owned->id, $owned->attempt_token);
                }
                $this->dispatch->release($owned);
                $cancelled = $run->cancel_requested_at !== null || $run->library_id === null || $owned->media_file_id === null;
                $owned->update([
                    'status' => $cancelled ? TaskStatus::CANCELLED : TaskStatus::PENDING,
                    'request_token' => (string) Str::uuid(), 'attempt_token' => null, 'unique_lock_owner' => null,
                    'dispatch_intended_at' => null, 'dispatched_at' => null, 'execution_deadline_at' => null,
                    'heartbeat_at' => null, 'started_at' => null, 'finished_at' => $cancelled ? now() : null,
                    'output_path' => null, 'output_size_bytes' => null, 'progress_percent' => null, 'encoded_seconds' => null,
                ]);

                return true;
            });
        } finally {
            $lock->release();
        }
    }
}
