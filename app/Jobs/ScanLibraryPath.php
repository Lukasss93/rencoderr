<?php

namespace App\Jobs;

use App\Library\LibraryPathLock;
use App\Library\MediaScanner;
use App\Models\LibraryPath;
use App\Tasks\Enums\TaskStatus;
use App\Tasks\Enums\WorkQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

class ScanLibraryPath implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    /**
     * The worker stops a scan after this many seconds, so a scan that has made no progress
     * for longer than this is treated as lost and the folder can be queued again.
     */
    public const int TIMEOUT_SECONDS = 1800;

    public int $timeout = self::TIMEOUT_SECONDS;

    public int $tries = 0;

    public int $maxExceptions = 1;

    public bool $failOnTimeout = true;

    public function __construct(public int $pathId, public string $token)
    {
        $this->onQueue(WorkQueue::SCANS);
    }

    public function handle(MediaScanner $scanner): void
    {
        $lock = LibraryPathLock::forPath($this->pathId, self::TIMEOUT_SECONDS + 60);
        if (! $lock->get()) {
            $this->release(30);

            return;
        }

        try {
            $claimed = LibraryPath::query()->scanRequest($this->pathId, $this->token)
                ->where('scan_status', TaskStatus::QUEUED)
                ->update(['scan_status' => TaskStatus::RUNNING, 'scan_started_at' => now()]);

            if ($claimed === 1) {
                $scanner->scan($this->pathId, $this->token);
            }
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        LibraryPath::query()->scanRequest($this->pathId, $this->token)
            ->whereIn('scan_status', [TaskStatus::QUEUED, TaskStatus::RUNNING])
            ->update([
                'scan_status' => TaskStatus::FAILED,
                'scan_finished_at' => now(),
                'scan_error' => $exception?->getMessage() ?? 'The scan failed.',
            ]);
    }
}
