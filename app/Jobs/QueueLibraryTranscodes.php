<?php

namespace App\Jobs;

use App\Models\TranscodeRun;
use App\Tasks\Enums\WorkQueue;
use App\Transcoding\Actions\EnumerateTranscodeRun;
use App\Transcoding\Actions\FinalizeTranscodeRun;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Str;
use Throwable;

class QueueLibraryTranscodes implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 1;

    public int $timeout = 60;

    public function __construct(public int $runId, public string $requestToken)
    {
        $this->onQueue(WorkQueue::DEFAULT);
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('transcode-run:'.$this->runId))->releaseAfter(60)->expireAfter(120)];
    }

    public function handle(EnumerateTranscodeRun $enumerate, Dispatcher $bus): void
    {
        if ($enumerate->handle($this->runId, $this->requestToken)) {
            $bus->dispatch(new self($this->runId, $this->requestToken));
        }
    }

    public function failed(?Throwable $exception): void
    {
        TranscodeRun::query()->whereKey($this->runId)->where('request_token', $this->requestToken)->active()
            ->update(['error' => Str::limit($exception?->getMessage() ?? 'Enumeration failed.', 4000), 'enumeration_finished_at' => now()]);
        app(FinalizeTranscodeRun::class)->handle($this->runId);
    }
}
