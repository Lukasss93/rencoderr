<?php

namespace App\Jobs;

use App\Models\MediaWriteback;
use App\Tasks\Enums\TaskStatus;
use App\Tasks\Enums\WorkQueue;
use App\Writeback\Actions\RunWriteback;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;
use Throwable;

class WriteMediaFile implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 0;

    public bool $failOnTimeout = true;

    public int $timeout;

    public function __construct(public int $writebackId, public string $requestToken)
    {
        $this->timeout = max(1, (int) config('writeback.timeout_seconds', 21600));
        $this->onConnection('redis-writeback')->onQueue(WorkQueue::WRITEBACK);
    }

    public function handle(RunWriteback $run): void
    {
        $run->handle($this->writebackId, $this->requestToken);
        if (MediaWriteback::query()->whereKey($this->writebackId)->where('request_token', $this->requestToken)
            ->where('status', TaskStatus::QUEUED)->exists()) {
            $this->release(30);
        }
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['writeback:'.$this->writebackId];
    }

    public function failed(?Throwable $exception): void
    {
        MediaWriteback::query()->whereKey($this->writebackId)->where('request_token', $this->requestToken)
            ->whereIn('status', [TaskStatus::QUEUED, TaskStatus::RUNNING])->update([
                'status' => TaskStatus::FAILED, 'finished_at' => now(),
                'error' => Str::limit($exception?->getMessage() ?? 'The write-back worker stopped.', 4000),
            ]);
    }
}
