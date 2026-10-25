<?php

namespace App\Workers;

use App\Models\Setting;
use App\Workers\Enums\WorkerPool;

class WorkerLimit
{
    public function get(WorkerPool $pool = WorkerPool::STANDARD): int
    {
        $value = Setting::valueOf($pool->settingKey());

        return is_int($value) && $value > 0
            ? $value
            : max(1, (int) config('horizon.defaults.'.$pool->supervisor().'.maxProcesses', 1));
    }

    public function save(int $maxWorkers, WorkerPool $pool = WorkerPool::STANDARD): void
    {
        Setting::put($pool->settingKey(), $maxWorkers);
    }
}
