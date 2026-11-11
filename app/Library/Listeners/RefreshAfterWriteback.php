<?php

namespace App\Library\Listeners;

use App\Library\Events\MediaFileReplaced;
use App\Library\FileFingerprint;
use App\Models\MediaFile;
use App\Models\MediaProbe;
use App\Models\MediaWriteback;
use App\Tasks\Enums\TaskStatus;
use App\Transcoding\StagingArea;
use App\Writeback\Events\WritebackInstalled;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RefreshAfterWriteback
{
    public function __construct(private StagingArea $staging) {}

    public function handle(WritebackInstalled $event): void
    {
        $writeback = MediaWriteback::query()->with('transcode.mediaFile.libraryPath')->findOrFail($event->writebackId);
        if ($writeback->status === TaskStatus::COMPLETED && $writeback->inventory_refreshed_at === null) {
            $this->refreshInventory($writeback);
        }
        $writeback->refresh();
        if ($writeback->status === TaskStatus::COMPLETED && $writeback->inventory_refreshed_at !== null && $writeback->staging_cleaned_at === null) {
            $item = $writeback->transcode()->firstOrFail();
            $directory = $this->staging->directory($item->id, (string) $item->attempt_token);
            if ($this->staging->disk()->exists($directory)) {
                if ($this->staging->disk()->exists((string) $item->output_path)
                    && ! FileFingerprint::matches($writeback->staging_path, $writeback->output_fingerprint)) {
                    throw new RuntimeException('The staged file changed before write-back cleanup.');
                }
                $this->staging->clean($item->id, (string) $item->attempt_token);
            }
            $writeback->update(['staging_cleaned_at' => now()]);
        }
    }

    private function refreshInventory(MediaWriteback $writeback): void
    {
        $file = $writeback->transcode()->firstOrFail()->mediaFile;
        $root = $file?->libraryPath;
        if ($file === null || $root === null) {
            throw new RuntimeException('The library was removed before replacement inventory was refreshed.');
        }
        $prefix = rtrim($root->path, '/').'/';
        if (! str_starts_with($writeback->destination_path, $prefix)
            || ! FileFingerprint::matches($writeback->destination_path, $writeback->output_fingerprint)) {
            throw new RuntimeException('The installed file changed before inventory could be refreshed.');
        }
        $relative = substr($writeback->destination_path, strlen($prefix));
        $metadata = @stat($writeback->destination_path);
        if ($metadata === false) {
            throw new RuntimeException('The installed file metadata could not be read.');
        }

        DB::transaction(function () use ($writeback, $file, $root, $relative, $metadata): void {
            $writeback = MediaWriteback::query()->lockForUpdate()->findOrFail($writeback->id);
            if ($writeback->status !== TaskStatus::COMPLETED || $writeback->inventory_refreshed_at !== null) {
                return;
            }
            $duplicate = MediaFile::query()->where('library_path_id', $root->id)->where('path_hash', hash('sha256', $relative))
                ->whereKeyNot($file->id)->lockForUpdate()->first();
            if ($duplicate !== null) {
                $conflictingRuns = $file->transcodes()->pluck('transcode_run_id');
                $duplicate->transcodes()->whereIn('transcode_run_id', $conflictingRuns)->update(['media_file_id' => null]);
                $duplicate->transcodes()->update(['media_file_id' => $file->id]);
                $duplicate->delete();
            }
            $file->update(['relative_path' => $relative, 'path_hash' => hash('sha256', $relative),
                'extension' => strtolower(pathinfo($relative, PATHINFO_EXTENSION)), 'size_bytes' => $metadata['size'],
                'filesystem_modified_at' => date('Y-m-d H:i:s', $metadata['mtime']), 'last_seen_at' => now()]);
            $file->probe()->update(MediaProbe::pendingAttributes());
            $writeback->update(['inventory_refreshed_at' => now()]);
            event(new MediaFileReplaced($file->id));
        });
    }
}
