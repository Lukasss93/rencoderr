<?php

namespace App\Transcoding\Actions;

use App\Jobs\QueueLibraryTranscodes;
use App\Models\Library;
use App\Models\MediaFile;
use App\Models\TranscodeRun;
use App\Tasks\Enums\TaskStatus;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RequestTranscodeRun
{
    public function __construct(
        private Dispatcher $bus,
        private QueueTranscodeFiles $queueFiles,
        private FinalizeTranscodeRun $finalize,
    ) {}

    public function handle(Library $library): TranscodeRun
    {
        return $this->request($library, null);
    }

    public function forFile(MediaFile $file): TranscodeRun
    {
        $run = DB::transaction(function () use ($file): TranscodeRun {
            $library = Library::query()->lockForUpdate()->findOrFail($file->libraryPath()->firstOrFail()->library_id);
            $file = MediaFile::query()->with(['probe', 'libraryPath'])->findOrFail($file->id);
            $reason = $file->transcodeUnavailableReason();
            if ($reason !== null) {
                throw ValidationException::withMessages(['transcode' => $reason]);
            }
            $active = TranscodeRun::query()->where('library_id', $library->id)->active()->first();
            if ($active !== null) {
                if ($active->cancel_requested_at === null && $active->transcodes()->where('media_file_id', $file->id)->exists()) {
                    return $active;
                }

                throw ValidationException::withMessages(['transcode' => 'This library already has an active transcode run. Follow its progress on the Queue page.']);
            }
            $run = TranscodeRun::query()->create([
                'library_id' => $library->id, 'library_name' => $library->name,
                'request_token' => (string) Str::uuid(), 'status' => TaskStatus::RUNNING,
                'enumeration_finished_at' => now(), 'started_at' => now(),
            ]);
            $this->queueFiles->handle($run, new Collection([$file]));
            $run->save();

            return $run;
        });
        $this->finalize->handle($run->id);

        return $run->refresh();
    }

    public function retryFailed(TranscodeRun $run): TranscodeRun
    {
        $run->refresh();
        $library = $run->library;
        if ($library === null || $run->finished_at === null || ! $run->transcodes()->where('status', TaskStatus::FAILED)->exists()) {
            throw ValidationException::withMessages(['run' => 'Only a finished run with failed files and an existing library can be retried.']);
        }

        return $this->request($library, $run);
    }

    private function request(Library $library, ?TranscodeRun $retry): TranscodeRun
    {
        return DB::transaction(function () use ($library, $retry): TranscodeRun {
            $library = Library::query()->lockForUpdate()->findOrFail($library->id);
            $active = TranscodeRun::query()->where('library_id', $library->id)->active()->first();
            if ($active !== null) {
                return $active;
            }
            $run = TranscodeRun::query()->create([
                'library_id' => $library->id, 'retry_of_run_id' => $retry?->id,
                'library_name' => $library->name, 'request_token' => (string) Str::uuid(),
                'status' => TaskStatus::QUEUED,
                'inventory_cutoff_id' => (int) ($library->mediaFiles()->max('media_files.id') ?? 0),
            ]);
            DB::afterCommit(fn () => rescue(fn () => $this->bus->dispatch(new QueueLibraryTranscodes($run->id, $run->request_token))));

            return $run;
        });
    }
}
