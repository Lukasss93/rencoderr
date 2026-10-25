<?php

namespace App\Transcoding\Actions;

use App\Jobs\QueueLibraryTranscodes;
use App\Jobs\TranscodeMediaFile;
use App\Models\MediaTranscode;
use App\Models\TranscodeRun;
use App\Tasks\Enums\TaskStatus;
use App\Transcoding\StagingArea;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Str;

class RecoverTranscodes
{
    public function __construct(
        private CancelTranscodeRun $cancel,
        private FinalizeTranscodeRun $finalize,
        private StagingArea $staging,
        private Dispatcher $bus,
    ) {}

    /**
     * Only call during exclusive startup, after every worker and FFmpeg process has stopped. New
     * tokens make jobs still waiting in Redis harmless. A failed dispatch is thrown so startup stops.
     */
    public function handle(): int
    {
        foreach (TranscodeRun::query()->active()->lazyById(200) as $run) {
            if ($run->library_id === null || $run->cancel_requested_at !== null) {
                $this->cancel->handle($run);
            } elseif ($run->enumeration_finished_at === null) {
                $token = (string) Str::uuid();
                $run->update(['request_token' => $token]);
                $this->bus->dispatch(new QueueLibraryTranscodes($run->id, $token));
            }
        }

        $recovered = 0;
        foreach (MediaTranscode::query()->active()->with('run')->lazyById(200) as $item) {
            if ($item->attempt_token !== null) {
                $this->staging->clean($item->id, $item->attempt_token);
            }
            $cancelled = $item->media_file_id === null || $item->run->cancel_requested_at !== null || $item->run->library_id === null;
            $token = (string) Str::uuid();
            $item->update([
                'status' => $cancelled ? TaskStatus::CANCELLED : TaskStatus::QUEUED, 'request_token' => $token,
                'attempt_token' => null, 'execution_deadline_at' => null, 'started_at' => null, 'finished_at' => $cancelled ? now() : null,
            ]);
            if (! $cancelled) {
                $this->bus->dispatch(new TranscodeMediaFile($item->id, $token));
                $recovered++;
            }
        }

        foreach (TranscodeRun::query()->active()->lazyById(200) as $run) {
            $this->finalize->handle($run->id);
        }

        return $recovered;
    }
}
