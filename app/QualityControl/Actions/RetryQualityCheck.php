<?php

namespace App\QualityControl\Actions;

use App\Jobs\CheckMediaQuality;
use App\Models\MediaQualityCheck;
use App\Tasks\Enums\TaskStatus;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Str;

class RetryQualityCheck
{
    public function __construct(private Dispatcher $bus) {}

    public function handle(MediaQualityCheck $check): MediaQualityCheck
    {
        return $this->queue($check, [TaskStatus::FAILED, TaskStatus::CANCELLED]);
    }

    public function interrupted(MediaQualityCheck $check): MediaQualityCheck
    {
        return $this->queue($check, [TaskStatus::QUEUED, TaskStatus::RUNNING]);
    }

    /** @param list<TaskStatus> $statuses */
    private function queue(MediaQualityCheck $check, array $statuses): MediaQualityCheck
    {
        $token = (string) Str::uuid();
        $updated = MediaQualityCheck::query()->forRequest($check->id, $check->request_token)
            ->whereIn('status', $statuses)->update([
                'request_token' => $token, 'attempt_token' => null, 'status' => TaskStatus::QUEUED,
                'execution_limit_seconds' => (int) config('quality_control.timeout_seconds'), 'threads' => (int) config('quality_control.threads'),
                'verdict' => null, 'phase' => null, 'results' => null, 'error' => null, 'reason' => null, 'diagnostic_tail' => null,
                'vmaf_mean' => null, 'savings_percent' => null, 'coverage_seconds' => null, 'progress_percent' => null,
                'cancel_requested_at' => null, 'execution_deadline_at' => null, 'started_at' => null, 'finished_at' => null,
            ]);
        $check->refresh();
        if ($updated === 1) {
            $this->bus->dispatch(new CheckMediaQuality($check->id, $token, $check->execution_limit_seconds));
        }

        return $check;
    }
}
