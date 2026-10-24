<?php

namespace App\Transcoding\Actions;

use App\Library\Enums\MediaFileStatus;
use App\Models\MediaFile;
use App\Models\MediaTranscode;
use App\Models\TranscodeRun;
use App\Tasks\Enums\TaskStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EnumerateTranscodeRun
{
    public function __construct(private DispatchTranscode $dispatch, private FinalizeTranscodeRun $finalize) {}

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
            foreach ($files as $file) {
                $run->inventory_cursor_id = $file->id;
                if ($file->status !== MediaFileStatus::PRESENT) {
                    continue;
                }
                if (! $file->hasCurrentProbe()) {
                    $run->excluded_metadata_count++;

                    continue;
                }
                $root = $file->libraryPath;
                if ($root === null) {
                    continue;
                }
                $source = rtrim($root->path, '/').'/'.$file->relative_path;
                $retained = MediaTranscode::query()->where('media_file_id', $file->id)->where('status', TaskStatus::COMPLETED)
                    ->where('source_path', $source)->where('source_size_bytes', $file->size_bytes)
                    ->where('source_modified_at', $file->filesystem_modified_at)->get(['output_path']);
                if ($retained->contains(fn (MediaTranscode $item): bool => $item->output_path !== null && Storage::disk(config('transcoding.staging_disk'))->exists($item->output_path))) {
                    $run->retained_output_count++;

                    continue;
                }
                $item = $run->transcodes()->firstOrCreate(['media_file_id' => $file->id], [
                    'file_identity_id' => $file->id, 'request_token' => (string) Str::uuid(), 'status' => TaskStatus::PENDING,
                    'source_path' => $source, 'source_size_bytes' => $file->size_bytes, 'source_modified_at' => $file->filesystem_modified_at,
                    'execution_limit_seconds' => (int) config('transcoding.timeout_seconds'),
                ]);
                DB::afterCommit(fn () => rescue(fn () => $this->dispatch->handle($item->id)));
            }
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
