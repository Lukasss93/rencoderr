<?php

namespace App\Jobs;

use App\Models\MediaProbe;
use App\Probe\Actions\RunProbe;
use App\Probe\Exceptions\ProbeFailed;
use App\Tasks\Enums\TaskStatus;
use App\Tasks\Enums\WorkQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

class ProbeMediaFile implements ShouldQueue
{
    use Queueable;

    public const int MAX_ATTEMPTS = 3;

    public const int TIMEOUT_SECONDS = 150;

    public int $tries = self::MAX_ATTEMPTS;

    public int $timeout = self::TIMEOUT_SECONDS;

    public function __construct(public int $fileId, public string $token, public int $previousAttempts = 0)
    {
        $this->onQueue(WorkQueue::PROBES);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return $this->previousAttempts === 0 ? [30, 120] : [120];
    }

    public function handle(RunProbe $probe): void
    {
        try {
            $probe->handle($this->fileId, $this->token);
        } catch (ProbeFailed $exception) {
            $this->fail($exception->getPrevious());
        }
    }

    public function failed(?Throwable $exception): void
    {
        MediaProbe::query()->forRequest($this->fileId, $this->token)->claimable(self::TIMEOUT_SECONDS)->update([
            'status' => TaskStatus::FAILED, 'finished_at' => now(),
            'error' => Str::limit($exception?->getMessage() ?? 'The probe failed.', 4000),
        ]);
    }
}
