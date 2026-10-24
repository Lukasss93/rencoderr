<?php

namespace App\Models\Builders;

use App\Models\LibraryPath;
use App\Tasks\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Builder;

/** @extends Builder<LibraryPath> */
class LibraryPathBuilder extends Builder
{
    public function scanRequest(int $pathId, string $token): static
    {
        return $this->whereKey($pathId)->where('scan_token', $token);
    }

    public function runningScan(int $pathId, string $token): static
    {
        return $this->scanRequest($pathId, $token)->where('scan_status', TaskStatus::RUNNING);
    }

    public function queueScan(string $token): int
    {
        return $this->update([
            'scan_status' => TaskStatus::QUEUED,
            'scan_token' => $token,
            'scan_requested_at' => now(),
            'scan_started_at' => null,
            'scan_finished_at' => null,
            'scan_error' => null,
        ]);
    }

    public function claimScan(): bool
    {
        return $this->where('scan_status', TaskStatus::QUEUED)
            ->update(['scan_status' => TaskStatus::RUNNING, 'scan_started_at' => now()]) === 1;
    }

    public function completeScan(): bool
    {
        return $this->where('scan_status', TaskStatus::RUNNING)->update([
            'scan_status' => TaskStatus::COMPLETED,
            'scan_finished_at' => now(),
            'last_successful_scan_at' => now(),
            'scan_error' => null,
        ]) === 1;
    }

    public function failScan(string $error): int
    {
        return $this->whereIn('scan_status', [TaskStatus::QUEUED, TaskStatus::RUNNING])->update([
            'scan_status' => TaskStatus::FAILED,
            'scan_finished_at' => now(),
            'scan_error' => $error,
        ]);
    }
}
