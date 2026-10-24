<?php

namespace App\Library\Actions;

use App\Jobs\ScanLibraryPath;
use App\Models\Library;
use App\Models\LibraryPath;
use App\Tasks\Enums\TaskStatus;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class RequestLibraryScan
{
    public function __construct(private Dispatcher $bus) {}

    public function handle(Library $library): int
    {
        $queued = $this->queueAll($library->paths()->get());

        if ($queued > 0) {
            $library->update(['last_scan_requested_at' => now()]);
        }

        return $queued;
    }

    /**
     * Leaves the automatic scan timer alone, so adding a folder does not delay the next scan of the
     * others. A library that was never scanned starts its timer here.
     */
    public function forNewPaths(Library $library): int
    {
        $queued = $this->queueAll($library->paths()->where('scan_status', TaskStatus::PENDING)->get());

        if ($queued > 0 && $library->last_scan_requested_at === null) {
            $library->update(['last_scan_requested_at' => now()]);
        }

        return $queued;
    }

    public function due(): int
    {
        $queued = 0;

        Library::query()->where('automatic_scanning', true)
            ->chunkById(100, function (Collection $libraries) use (&$queued): void {
                foreach ($libraries as $library) {
                    if ($library->last_scan_requested_at === null
                        || $library->last_scan_requested_at->addMinutes($library->scan_interval_minutes)->lessThanOrEqualTo(now())) {
                        $queued += $this->handle($library);
                    }
                }
            });

        return $queued;
    }

    /** @param Collection<int, LibraryPath> $paths */
    private function queueAll(Collection $paths): int
    {
        return $paths->filter(fn (LibraryPath $path): bool => $this->queue($path))->count();
    }

    private function queue(LibraryPath $path): bool
    {
        if ($path->hasPendingScan()) {
            return false;
        }

        // A lost queued scan keeps its token, so if the original job does turn up,
        // it and the replacement compete for the same claim and only one of them scans.
        $token = $path->scan_status === TaskStatus::QUEUED && $path->scan_token !== null
            ? $path->scan_token
            : (string) Str::uuid();

        if (! $path->queueScan($token)) {
            return false;
        }

        $job = new ScanLibraryPath($path->id, $token);
        DB::afterCommit(fn () => $this->dispatch($job));

        return true;
    }

    private function dispatch(ScanLibraryPath $job): void
    {
        try {
            $this->bus->dispatch($job);
        } catch (Throwable $exception) {
            $job->failed($exception);
            report($exception);
        }
    }
}
