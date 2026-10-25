<?php

namespace App\QualityControl\Actions;

use App\Models\MediaQualityCheck;
use App\Tasks\Enums\TaskStatus;

class CancelQualityCheck
{
    public function handle(MediaQualityCheck $check): void
    {
        $current = fn () => MediaQualityCheck::query()->forRequest($check->id, $check->request_token);
        $current()->where('status', TaskStatus::QUEUED)->update([
            'status' => TaskStatus::CANCELLED, 'cancel_requested_at' => now(), 'finished_at' => now(),
        ]);
        $current()->where('status', TaskStatus::RUNNING)->update(['cancel_requested_at' => now()]);
    }
}
