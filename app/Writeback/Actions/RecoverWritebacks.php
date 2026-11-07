<?php

namespace App\Writeback\Actions;

use App\Jobs\WriteMediaFile;
use App\Models\MediaQualityCheck;
use App\Models\MediaWriteback;
use App\QualityControl\Enums\QualityVerdict;
use App\Tasks\Enums\TaskStatus;
use App\Writeback\Events\WritebackInstalled;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class RecoverWritebacks
{
    public function __construct(private RequestWriteback $requests) {}

    /** Only run during exclusive startup, before starting workers. */
    public function handle(): int
    {
        $recovered = 0;
        $interrupted = MediaWriteback::query()->whereIn('status', [TaskStatus::QUEUED, TaskStatus::RUNNING])->pluck('id');
        foreach (MediaQualityCheck::query()->where('status', TaskStatus::COMPLETED)->where('verdict', QualityVerdict::PASSED)
            ->whereHas('transcode', fn ($query) => $query->whereNotNull('writeback_mode')->whereDoesntHave('writeback'))->lazyById(200) as $check) {
            if ($this->requests->handle($check) !== null) {
                $recovered++;
            }
        }
        foreach (MediaWriteback::query()->whereIn('id', $interrupted)->lazyById(200) as $writeback) {
            Cache::lock('writeback-source:'.hash('sha256', $writeback->source_path))->forceRelease();
            $token = (string) Str::uuid();
            $writeback->update(['request_token' => $token, 'attempt_token' => null, 'status' => TaskStatus::QUEUED]);
            WriteMediaFile::dispatch($writeback->id, $token);
            $recovered++;
        }
        foreach (MediaWriteback::query()->where('status', TaskStatus::COMPLETED)->where(fn ($query) => $query->whereNull('inventory_refreshed_at')->orWhereNull('staging_cleaned_at'))->lazyById(200) as $writeback) {
            event(new WritebackInstalled($writeback->id));
            $recovered++;
        }

        return $recovered;
    }
}
