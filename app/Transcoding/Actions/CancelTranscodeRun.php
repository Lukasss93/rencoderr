<?php

namespace App\Transcoding\Actions;

use App\Models\TranscodeRun;
use App\Tasks\Enums\TaskStatus;
use Illuminate\Support\Facades\DB;

class CancelTranscodeRun
{
    public function __construct(private FinalizeTranscodeRun $finalize) {}

    /**
     * Running files notice the cancellation themselves and remove their incomplete output.
     */
    public function handle(TranscodeRun $run): void
    {
        DB::transaction(function () use ($run): void {
            $run = TranscodeRun::query()->whereKey($run->id)->active()->lockForUpdate()->first();
            if ($run === null) {
                return;
            }
            $run->update(['cancel_requested_at' => $run->cancel_requested_at ?? now(), 'enumeration_finished_at' => $run->enumeration_finished_at ?? now()]);
            $run->transcodes()->whereIn('status', [TaskStatus::PENDING, TaskStatus::QUEUED])->update(['status' => TaskStatus::CANCELLED, 'finished_at' => now()]);
        });
        $this->finalize->handle($run->id);
    }
}
