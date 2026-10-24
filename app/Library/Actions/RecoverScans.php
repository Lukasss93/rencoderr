<?php

namespace App\Library\Actions;

use App\Jobs\ScanLibraryPath;
use App\Models\LibraryPath;
use App\Tasks\Enums\TaskStatus;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Str;

class RecoverScans
{
    public function __construct(private Dispatcher $bus) {}

    /**
     * A failed dispatch deliberately leaves the path queued for the next startup attempt.
     */
    public function handle(): int
    {
        $recovered = 0;

        foreach (LibraryPath::query()->whereIn('scan_status', [TaskStatus::QUEUED, TaskStatus::RUNNING])->lazyById() as $path) {
            $token = (string) Str::uuid();
            LibraryPath::query()->whereKey($path->id)->queueScan($token);
            $this->bus->dispatch(new ScanLibraryPath($path->id, $token));
            $recovered++;
        }

        return $recovered;
    }
}
