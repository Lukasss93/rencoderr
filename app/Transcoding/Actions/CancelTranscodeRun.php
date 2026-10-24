<?php

namespace App\Transcoding\Actions;

use App\Models\TranscodeRun;
use App\Tasks\Enums\TaskStatus;
use Illuminate\Support\Facades\DB;

class CancelTranscodeRun
{
    public function __construct(private DispatchTranscode $dispatch, private FinalizeTranscodeRun $finalize) {}

    public function handle(TranscodeRun $run): void
    {
        DB::transaction(function () use ($run): void {
            $run = TranscodeRun::query()->whereKey($run->id)->active()->lockForUpdate()->first();
            if ($run === null) {
                return;
            }
            $run->update(['cancel_requested_at' => $run->cancel_requested_at ?? now(), 'enumeration_finished_at' => $run->enumeration_finished_at ?? now()]);
            foreach ($run->transcodes()->whereIn('status', [TaskStatus::PENDING, TaskStatus::QUEUED])->lockForUpdate()->lazyById(200) as $item) {
                $item->update(['status' => TaskStatus::CANCELLED, 'finished_at' => now()]);
                DB::afterCommit(fn () => $this->dispatch->release($item));
            }
        });
        $this->finalize->handle($run->id);
    }
}
