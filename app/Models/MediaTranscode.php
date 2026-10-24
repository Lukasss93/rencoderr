<?php

namespace App\Models;

use App\Tasks\Enums\TaskStatus;
use Carbon\CarbonImmutable;
use Database\Factories\MediaTranscodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @property TranscodeRun $run
 * @property TaskStatus $status
 * @property CarbonImmutable $source_modified_at
 * @property CarbonImmutable|null $execution_deadline_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property list<string>|null $arguments
 */
#[Fillable(['transcode_run_id', 'media_file_id', 'file_identity_id', 'request_token', 'attempt_token', 'unique_lock_owner', 'status', 'source_path', 'source_size_bytes', 'source_modified_at', 'execution_limit_seconds', 'encoder', 'arguments', 'stream_decisions', 'output_path', 'output_size_bytes', 'progress_percent', 'encoded_seconds', 'diagnostic_tail', 'error', 'skip_reason', 'dispatch_intended_at', 'dispatched_at', 'execution_deadline_at', 'heartbeat_at', 'started_at', 'finished_at'])]
class MediaTranscode extends Model
{
    /** @use HasFactory<MediaTranscodeFactory> */
    use HasFactory;

    /** @return BelongsTo<TranscodeRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(TranscodeRun::class, 'transcode_run_id');
    }

    /** @return BelongsTo<MediaFile, $this> */
    public function mediaFile(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class);
    }

    /** @return MorphMany<Setting, $this> */
    public function settings(): MorphMany
    {
        return $this->morphMany(Setting::class, 'settingable');
    }

    /** @param Builder<MediaTranscode> $query */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereIn('status', [TaskStatus::PENDING, TaskStatus::QUEUED, TaskStatus::RUNNING]);
    }

    /** @param Builder<MediaTranscode> $query */
    #[Scope]
    protected function forRequest(Builder $query, int $id, string $token): void
    {
        $query->whereKey($id)->where('request_token', $token);
    }

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class, 'source_size_bytes' => 'integer', 'file_identity_id' => 'integer',
            'execution_limit_seconds' => 'integer', 'output_size_bytes' => 'integer',
            'source_modified_at' => 'immutable_datetime', 'dispatch_intended_at' => 'immutable_datetime',
            'dispatched_at' => 'immutable_datetime', 'execution_deadline_at' => 'immutable_datetime',
            'heartbeat_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime',
            'arguments' => 'array', 'stream_decisions' => 'array', 'progress_percent' => 'float', 'encoded_seconds' => 'float',
        ];
    }
}
