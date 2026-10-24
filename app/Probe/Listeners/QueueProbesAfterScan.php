<?php

namespace App\Probe\Listeners;

use App\Library\Enums\MediaFileStatus;
use App\Library\Events\LibraryPathScanned;
use App\Models\MediaFile;
use App\Probe\Actions\RequestProbe;
use App\Tasks\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Builder;

class QueueProbesAfterScan
{
    public function __construct(private RequestProbe $requests) {}

    public function handle(LibraryPathScanned $event): void
    {
        $files = MediaFile::query()->where('library_path_id', $event->pathId)
            ->where('last_seen_scan_token', $event->token)
            ->where('status', MediaFileStatus::PRESENT)
            ->whereHas('probe', fn (Builder $query) => $query->where('status', TaskStatus::PENDING));

        foreach ($files->lazyById(200) as $file) {
            $this->requests->afterScan($file->id, $event->token);
        }
    }
}
