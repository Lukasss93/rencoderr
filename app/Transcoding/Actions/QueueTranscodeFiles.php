<?php

namespace App\Transcoding\Actions;

use App\Jobs\TranscodeMediaFile;
use App\Library\Enums\MediaFileStatus;
use App\Models\MediaFile;
use App\Models\MediaTranscode;
use App\Models\TranscodeRun;
use App\QualityControl\Enums\QualityVerdict;
use App\Tasks\Enums\TaskStatus;
use App\Transcoding\OutputContainer;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class QueueTranscodeFiles
{
    public function __construct(private Dispatcher $bus) {}

    /**
     * The caller holds the library or run lock and saves the updated run counters.
     *
     * @param  Collection<int, MediaFile>  $files
     */
    public function handle(TranscodeRun $run, Collection $files): void
    {
        $completed = MediaTranscode::query()->whereIn('media_file_id', $files->modelKeys())->where('status', TaskStatus::COMPLETED)
            ->whereDoesntHave('qualityCheck', fn ($query) => $query->where('status', TaskStatus::COMPLETED)->where('verdict', QualityVerdict::REJECTED))
            ->whereNotNull('output_path')->get()->groupBy('media_file_id');
        $staging = Storage::disk(config('transcoding.staging_disk'));
        $writebackMode = $run->library?->writeback_mode;
        $waiting = MediaTranscode::query()->whereIn('media_file_id', $files->modelKeys())
            ->whereHas('writeback', fn ($query) => $query->unresolved())
            ->pluck('media_file_id')->all();
        foreach ($files as $file) {
            if (in_array($file->id, $waiting, true)) {
                continue;
            }
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
                && pathinfo((string) $item->output_path, PATHINFO_EXTENSION) === OutputContainer::forFile($file)->value
                && $staging->exists((string) $item->output_path));
            if ($retained) {
                $run->retained_output_count++;

                continue;
            }
            $item = $run->transcodes()->create([
                'writeback_mode' => $writebackMode,
                'media_file_id' => $file->id, 'request_token' => (string) Str::uuid(), 'status' => TaskStatus::QUEUED,
                'source_path' => $source, 'source_size_bytes' => $file->size_bytes, 'source_modified_at' => $file->filesystem_modified_at,
            ]);
            DB::afterCommit(fn () => rescue(fn () => $this->bus->dispatch(new TranscodeMediaFile($item->id, $item->request_token))));
        }
    }
}
