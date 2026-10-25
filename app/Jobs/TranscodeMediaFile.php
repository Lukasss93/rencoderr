<?php

namespace App\Jobs;

use App\Models\MediaTranscode;
use App\Tasks\Enums\TaskStatus;
use App\Tasks\Enums\WorkQueue;
use App\Transcoding\Actions\FinalizeTranscodeRun;
use App\Transcoding\Actions\RunTranscode;
use App\Transcoding\StagingArea;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

class TranscodeMediaFile implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public int $timeout;

    public function __construct(public int $transcodeId, public string $token)
    {
        // FFmpeg's own watchdog ends the encode at the deadline, so this only stops a worker stuck after it.
        $this->timeout = (int) config('transcoding.timeout_seconds') + 60;
        $this->onConnection('redis-transcodes')->onQueue(WorkQueue::TRANSCODES);
    }

    /** @return list<string> */
    public function tags(): array
    {
        $item = MediaTranscode::query()->with('run')->find($this->transcodeId);

        return ['transcode:'.$this->transcodeId, 'run:'.($item->transcode_run_id ?? 'deleted'), 'library:'.($item?->run->library_id ?? 'deleted')];
    }

    public function handle(RunTranscode $run): void
    {
        $run->handle($this->transcodeId, $this->token);
    }

    /**
     * Runs after an exception, a timeout, or a redelivery once the worker died, so the attempt
     * fails for good instead of being retried.
     */
    public function failed(?Throwable $exception): void
    {
        $item = MediaTranscode::query()->forRequest($this->transcodeId, $this->token)->active()->first();
        if ($item === null) {
            return;
        }
        $item->update([
            'status' => TaskStatus::FAILED, 'finished_at' => now(),
            'error' => Str::limit($exception?->getMessage() ?? 'The transcode failed.', 4000),
        ]);
        app(FinalizeTranscodeRun::class)->handle($item->transcode_run_id);
        if ($item->attempt_token !== null) {
            app(StagingArea::class)->clean($item->id, $item->attempt_token);
        }
    }
}
