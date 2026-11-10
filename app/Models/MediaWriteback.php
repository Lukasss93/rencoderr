<?php

namespace App\Models;

use App\QualityControl\Enums\QualityVerdict;
use App\Tasks\Enums\TaskStatus;
use App\Writeback\Enums\WritebackMode;
use Carbon\CarbonImmutable;
use Database\Factories\MediaWritebackFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property TaskStatus $status
 * @property WritebackMode $mode
 * @property string $source_fingerprint
 * @property string $output_fingerprint
 * @property array<string, mixed> $comparison
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable|null $installed_at
 * @property CarbonImmutable|null $inventory_refreshed_at
 */
#[Fillable(['media_transcode_id', 'media_quality_check_id', 'approved_by', 'status', 'mode', 'phase', 'request_token', 'attempt_token',
    'source_path', 'staging_path', 'candidate_path', 'copy_path', 'destination_path', 'backup_path', 'source_fingerprint', 'output_fingerprint',
    'comparison', 'error', 'approved_at', 'installed_at', 'inventory_refreshed_at', 'staging_cleaned_at', 'started_at', 'finished_at'])]
class MediaWriteback extends Model
{
    /** @use HasFactory<MediaWritebackFactory> */
    use HasFactory;

    /** @return BelongsTo<MediaTranscode, $this> */
    public function transcode(): BelongsTo
    {
        return $this->belongsTo(MediaTranscode::class, 'media_transcode_id');
    }

    /** @return BelongsTo<MediaQualityCheck, $this> */
    public function qualityCheck(): BelongsTo
    {
        return $this->belongsTo(MediaQualityCheck::class, 'media_quality_check_id');
    }

    /** @param Builder<MediaWriteback> $query */
    #[Scope]
    protected function unresolved(Builder $query): void
    {
        $query->whereIn('status', [TaskStatus::QUEUED, TaskStatus::RUNNING, TaskStatus::PENDING, TaskStatus::FAILED]);
    }

    public function awaitsApproval(): bool
    {
        return $this->status === TaskStatus::PENDING && $this->phase === 'awaiting_approval' && $this->approved_at === null
            && $this->qualityCheck?->status === TaskStatus::COMPLETED && $this->qualityCheck->verdict === QualityVerdict::PASSED;
    }

    protected function casts(): array
    {
        return ['status' => TaskStatus::class, 'mode' => WritebackMode::class,
            'source_fingerprint' => 'json', 'output_fingerprint' => 'json', 'comparison' => 'array',
            'approved_at' => 'immutable_datetime', 'installed_at' => 'immutable_datetime', 'staging_cleaned_at' => 'immutable_datetime', 'inventory_refreshed_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }
}
