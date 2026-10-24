<?php

namespace App\Transcoding\Actions;

use App\Models\TranscodeRun;
use App\Tasks\Enums\TaskStatus;
use Illuminate\Support\Facades\DB;

class FinalizeTranscodeRun
{
    public function handle(int $runId): void
    {
        DB::transaction(function () use ($runId): void {
            $run = TranscodeRun::query()->whereKey($runId)->active()->lockForUpdate()->first();
            if ($run === null || $run->enumeration_finished_at === null || $run->transcodes()->active()->exists()) {
                return;
            }
            $status = $run->cancel_requested_at !== null ? TaskStatus::CANCELLED
                : ($run->error !== null || $run->transcodes()->where('status', TaskStatus::FAILED)->exists() ? TaskStatus::FAILED : TaskStatus::COMPLETED);
            $run->update(['status' => $status, 'finished_at' => now()]);
        });
    }
}
