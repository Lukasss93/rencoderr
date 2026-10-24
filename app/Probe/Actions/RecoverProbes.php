<?php

namespace App\Probe\Actions;

use App\Models\MediaProbe;
use App\Tasks\Enums\TaskStatus;

class RecoverProbes
{
    public function __construct(private RequestProbe $requests) {}

    public function handle(): int
    {
        $recovered = 0;

        foreach (MediaProbe::query()->whereIn('status', [TaskStatus::PENDING, TaskStatus::QUEUED, TaskStatus::RUNNING])->lazyById(200) as $probe) {
            $recovered += (int) $this->requests->recover($probe->media_file_id);
        }

        return $recovered;
    }
}
