<?php

namespace App\Encoding\Actions;

use App\Encoding\Enums\ProbeStatus;
use App\Models\MediaProbe;

class RecoverMediaProbes
{
    public function __construct(private RequestMediaProbe $requests) {}

    /** Recover only during exclusive startup, before workers or requests start. */
    public function handle(): int
    {
        $recovered = 0;
        foreach (MediaProbe::query()->whereIn('status', [ProbeStatus::Pending, ProbeStatus::Queued, ProbeStatus::Probing])->lazyById(200) as $probe) {
            $recovered += (int) $this->requests->recover($probe->media_file_id);
        }

        return $recovered;
    }
}
