<?php

namespace App\Probe\Listeners;

use App\Library\Enums\MediaFileStatus;
use App\Library\Events\LibraryPathScanned;
use App\Models\MediaFile;
use App\Probe\Actions\RequestProbe;
use App\Tasks\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class QueueProbesAfterScan
{
    public function __construct(private RequestProbe $requests) {}

    /**
     * Requests each chunk in one transaction. Every commit takes the SQLite write lock, so one
     * commit per file would keep other workers waiting for the whole fan-out of a large scan.
     */
    public function handle(LibraryPathScanned $event): void
    {
        MediaFile::query()->where('library_path_id', $event->pathId)
            ->where('last_seen_scan_token', $event->token)
            ->where('status', MediaFileStatus::PRESENT)
            ->whereHas('probe', fn (Builder $query) => $query->where('status', TaskStatus::PENDING))
            ->chunkById(200, function (Collection $files) use ($event): void {
                DB::transaction(function () use ($files, $event): void {
                    foreach ($files as $file) {
                        $this->requests->afterScan($file->id, $event->token);
                    }
                });
            });
    }
}
