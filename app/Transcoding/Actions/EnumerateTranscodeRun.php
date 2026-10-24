<?php

namespace App\Transcoding\Actions;

use App\Jobs\TranscodeMediaFile;
use App\Library\Enums\MediaFileStatus;
use App\Models\MediaFile;
use App\Models\MediaTranscode;
use App\Models\TranscodeRun;
use App\Tasks\Enums\TaskStatus;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EnumerateTranscodeRun
{
    public function __construct(private Dispatcher $bus, private FinalizeTranscodeRun $finalize) {}

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
            $completed = MediaTranscode::query()->whereIn('media_file_id', $files->modelKeys())->where('status', TaskStatus::COMPLETED)
                ->whereNotNull('output_path')->get()->groupBy('media_file_id');
            $staging = Storage::disk(config('transcoding.staging_disk'));
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
                $retained = $completed->get($file->id, collect())->contains(fn (MediaTranscode $item): bool => $item->source_path === $source
                    && $item->source_size_bytes === $file->size_bytes && $item->source_modified_at->equalTo($file->filesystem_modified_at)
                    && $staging->exists((string) $item->output_path));
                if ($retained) {
                    $run->retained_output_count++;

                    continue;
                }
                $item = $run->transcodes()->create([
                    'media_file_id' => $file->id, 'request_token' => (string) Str::uuid(), 'status' => TaskStatus::QUEUED,
                    'source_path' => $source, 'source_size_bytes' => $file->size_bytes, 'source_modified_at' => $file->filesystem_modified_at,
                ]);
                DB::afterCommit(fn () => rescue(fn () => $this->bus->dispatch(new TranscodeMediaFile($item->id, $item->request_token))));
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
