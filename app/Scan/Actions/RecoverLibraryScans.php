<?php

namespace App\Scan\Actions;

use App\Jobs\ScanLibraryPath;
use App\Models\LibraryPath;
use App\Scan\Enums\ScanStatus;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Str;

class RecoverLibraryScans
{
    public function __construct(private Dispatcher $bus) {}

    /**
     * Replace pending work only during exclusive startup, before any workers or requests run.
     * A failed dispatch deliberately leaves the path queued for the next startup attempt.
     */
    public function handle(): int
    {
        $recovered = 0;

        foreach (LibraryPath::query()->whereIn('scan_status', [ScanStatus::Queued, ScanStatus::Scanning])->lazyById() as $path) {
            $token = (string) Str::uuid();
            $path->update([
                'scan_status' => ScanStatus::Queued,
                'scan_token' => $token,
                'scan_requested_at' => now(),
                'scan_started_at' => null,
                'scan_finished_at' => null,
                'scan_error' => null,
            ]);

            $this->bus->dispatch(new ScanLibraryPath($path->id, $token));
            $recovered++;
        }

        return $recovered;
    }
}
