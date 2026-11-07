<?php

namespace App\Writeback\Actions;

use App\Jobs\WriteMediaFile;
use App\Library\FileFingerprint;
use App\Models\MediaWriteback;
use App\Tasks\Enums\TaskStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ApproveWriteback
{
    public function handle(MediaWriteback $writeback, ?int $userId = null): void
    {
        DB::transaction(function () use ($writeback, $userId): void {
            $item = MediaWriteback::query()->with(['qualityCheck', 'transcode.mediaFile.libraryPath'])->lockForUpdate()->findOrFail($writeback->id);
            if ($item->approved_at !== null && in_array($item->status, [TaskStatus::QUEUED, TaskStatus::RUNNING, TaskStatus::COMPLETED], true)) {
                return;
            }
            $transcode = $item->transcode()->firstOrFail();
            $check = $item->qualityCheck()->firstOrFail();
            if (! $item->awaitsApproval() || $transcode->mediaFile?->libraryPath === null
                || $transcode->attempt_token !== $check->transcode_attempt_token) {
                throw ValidationException::withMessages(['writeback' => 'Only a verified copy awaiting approval can replace its source.']);
            }
            if (! FileFingerprint::matches($item->source_path, $item->source_fingerprint)
                || ! FileFingerprint::matches($item->candidate_path, $item->output_fingerprint, false)) {
                throw ValidationException::withMessages(['writeback' => 'The source or verified copy changed. Replacement was refused.']);
            }
            $token = (string) Str::uuid();
            $item->update(['status' => TaskStatus::QUEUED, 'request_token' => $token, 'attempt_token' => null,
                'approved_at' => now(), 'approved_by' => $userId, 'error' => null, 'finished_at' => null]);
            DB::afterCommit(fn () => rescue(fn () => WriteMediaFile::dispatch($item->id, $token)));
        });
    }
}
