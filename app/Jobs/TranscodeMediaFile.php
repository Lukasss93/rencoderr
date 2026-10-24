<?php

namespace App\Jobs;

use App\Models\MediaTranscode;
use App\Tasks\Enums\TaskStatus;
use App\Tasks\Enums\WorkQueue;
use App\Transcoding\Actions\FinalizeTranscodeRun;
use App\Transcoding\Actions\RunTranscode;
use App\Transcoding\TranscodeOutcome;
use Illuminate\Contracts\Queue\Interruptible;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Str;
use Throwable;

class TranscodeMediaFile implements Interruptible, ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 1;

    public bool $failOnTimeout = true;

    public int $timeout;

    private bool $stopping = false;

    private ?RunTranscode $activeRun = null;

    public function __construct(public int $transcodeId, public int $fileId, public string $requestToken, public int $executionLimitSeconds)
    {
        $this->timeout = $executionLimitSeconds + 60;
        $this->onConnection('redis-transcodes')->onQueue(WorkQueue::TRANSCODES);
    }

    public function uniqueId(): string
    {
        return 'media-file:'.$this->fileId;
    }

    public function uniqueFor(): int
    {
        return $this->executionLimitSeconds + 300;
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('transcode:media-file:'.$this->fileId))->shared()->releaseAfter(60)->expireAfter($this->executionLimitSeconds + 120)];
    }

    /** @return list<string> */
    public function tags(): array
    {
        $item = MediaTranscode::query()->with('run')->find($this->transcodeId);

        return ['transcode:'.$this->transcodeId, 'run:'.($item->transcode_run_id ?? 'deleted'), 'library:'.($item?->run->library_id ?? 'deleted')];
    }

    public function handle(RunTranscode $run): void
    {
        $this->activeRun = $run;
        try {
            $outcome = $run->handle($this->transcodeId, $this->requestToken, $this->executionLimitSeconds, fn (): bool => $this->stopping);
            if ($outcome === TranscodeOutcome::INTERRUPTED) {
                $this->release(60);
                MediaTranscode::query()->forRequest($this->transcodeId, $this->requestToken)
                    ->where('status', TaskStatus::QUEUED)->whereNull('dispatched_at')->update(['dispatched_at' => now()]);
            }
        } finally {
            $this->activeRun = null;
        }
    }

    public function interrupted(int $signal): void
    {
        $this->stopping = true;
        if ($signal === SIGALRM) {
            $this->activeRun?->stop();
        }
    }

    public function failed(?Throwable $exception): void
    {
        // Only waiting work can be changed by a rehydrated failure callback.
        $item = MediaTranscode::query()->forRequest($this->transcodeId, $this->requestToken)->where('status', TaskStatus::QUEUED)->first();
        if ($item === null) {
            return;
        }
        $waiting = MediaTranscode::query()->forRequest($this->transcodeId, $this->requestToken)
            ->where('status', TaskStatus::QUEUED)->where('attempt_token', $item->attempt_token);
        if ($item->attempt_token !== null) {
            $waiting->update(['dispatched_at' => null, 'error' => Str::limit($exception?->getMessage() ?? 'The interrupted job could not be released.', 4000)]);
        } else {
            $waiting->update(['status' => TaskStatus::FAILED, 'error' => Str::limit($exception?->getMessage() ?? 'The transcode job failed.', 4000), 'finished_at' => now()]);
            app(FinalizeTranscodeRun::class)->handle($item->transcode_run_id);
        }
    }
}
