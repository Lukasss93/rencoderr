<?php

namespace App\Workers;

use App\Models\Setting;

class WorkerLimit
{
    private const string SETTING = 'queue_max_workers';

    public function get(): int
    {
        $value = Setting::valueOf(self::SETTING);

        return is_int($value) && $value > 0
            ? $value
            : max(1, (int) config('horizon.defaults.'.HorizonWorkers::SUPERVISOR.'.maxProcesses', 1));
    }

    public function save(int $maxWorkers): void
    {
        Setting::put(self::SETTING, $maxWorkers);
    }
}
