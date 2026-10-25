<?php

namespace App\Jobs;

use App\Models\MediaQualityCheck;
use App\QualityControl\Actions\RunQualityCheck;
use App\Tasks\Enums\TaskStatus;
use App\Tasks\Enums\WorkQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

class CheckMediaQuality implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public int $timeout;

    public function __construct(public int $checkId, public string $requestToken, public int $executionLimitSeconds)
    {
        $this->timeout = $executionLimitSeconds + 60;
        $this->onConnection('redis-quality-control')->onQueue(WorkQueue::QUALITY_CONTROL);
    }

    public function handle(RunQualityCheck $run): void
    {
        $run->handle($this->checkId, $this->requestToken);
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['quality-check:'.$this->checkId];
    }

    public function failed(?Throwable $exception): void
    {
        MediaQualityCheck::query()->forRequest($this->checkId, $this->requestToken)->active()->whereNull('cancel_requested_at')->whereHas('transcode.mediaFile.libraryPath.library')->update([
            'status' => TaskStatus::FAILED, 'verdict' => null, 'finished_at' => now(), 'reason' => 'execution_failed',
            'error' => Str::limit($exception?->getMessage() ?? 'Quality control execution failed.', 4000),
        ]);
        MediaQualityCheck::query()->forRequest($this->checkId, $this->requestToken)->active()
            ->where(fn ($query) => $query->whereNotNull('cancel_requested_at')->orWhereDoesntHave('transcode.mediaFile.libraryPath.library'))
            ->update(['status' => TaskStatus::CANCELLED, 'verdict' => null, 'finished_at' => now(), 'reason' => 'cancelled']);
    }
}
