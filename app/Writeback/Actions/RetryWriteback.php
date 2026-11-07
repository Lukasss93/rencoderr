<?php

namespace App\Writeback\Actions;

use App\Jobs\WriteMediaFile;
use App\Models\MediaWriteback;
use App\Tasks\Enums\TaskStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RetryWriteback
{
    public function handle(MediaWriteback $writeback): void
    {
        DB::transaction(function () use ($writeback): void {
            $writeback = MediaWriteback::query()->lockForUpdate()->findOrFail($writeback->id);
            if ($writeback->status !== TaskStatus::FAILED) {
                throw ValidationException::withMessages(['writeback' => 'Only a failed write-back can be retried.']);
            }
            $token = (string) Str::uuid();
            $writeback->update(['request_token' => $token, 'status' => TaskStatus::QUEUED,
                'attempt_token' => null, 'error' => null, 'finished_at' => null]);
            DB::afterCommit(fn () => rescue(fn () => WriteMediaFile::dispatch($writeback->id, $token)));
        });
    }
}
