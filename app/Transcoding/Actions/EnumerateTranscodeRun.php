<?php

namespace App\Transcoding\Actions;

use App\Models\MediaFile;
use App\Models\MediaTranscode;
use App\Models\TranscodeRun;
use App\Tasks\Enums\TaskStatus;
use Illuminate\Support\Facades\DB;

class EnumerateTranscodeRun
{
    public function __construct(private QueueTranscodeFiles $queueFiles, private FinalizeTranscodeRun $finalize) {}

    /**
     * A failed dispatch leaves the file queued for startup recovery.
     */
    public function handle(int $runId, string $token): bool
    {
        $more = DB::transaction(function () use ($runId, $token): bool {
            $run = TranscodeRun::query()->whereKey($runId)->where('request_token', $token)->active()->lockForUpdate()->first();
            if ($run === null || $run->enumeration_finished_at !== null) {
                return false;
            }
            if ($run->cancel_requested_at !== null || $run->library_id === null) {
                $run->update(['cancel_requested_at' => $run->cancel_requested_at ?? now(), 'enumeration_finished_at' => now()]);

                return false;
            }
            $query = MediaFile::query()->with(['probe', 'libraryPath'])->whereHas('libraryPath', fn ($query) => $query->where('library_id', $run->library_id))
                ->where('id', '>', $run->inventory_cursor_id)->where('id', '<=', $run->inventory_cutoff_id)->orderBy('id');
            if ($run->retry_of_run_id !== null) {
                $query->whereIn('id', MediaTranscode::query()->select('media_file_id')->where('transcode_run_id', $run->retry_of_run_id)->where('status', TaskStatus::FAILED));
            }
            $files = $query->limit(200)->get();
            $this->queueFiles->handle($run, $files);
            $run->inventory_cursor_id = $files->last()->id ?? $run->inventory_cursor_id;
            $more = $files->count() === 200;
            $run->status = TaskStatus::RUNNING;
            $run->started_at ??= now()->toImmutable();
            if (! $more) {
                $run->enumeration_finished_at = now()->toImmutable();
            }
            $run->save();

            return $more;
        });
        $this->finalize->handle($runId);

        return $more;
    }
}
