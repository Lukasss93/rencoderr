<?php

namespace App\Models;

use App\QualityControl\Enums\QualityVerdict;
use App\Tasks\Enums\TaskStatus;
use Carbon\CarbonImmutable;
use Database\Factories\MediaQualityCheckFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property TaskStatus $status
 * @property array<string, mixed> $policy
 * @property array<string, mixed>|null $results
 * @property CarbonImmutable $source_modified_at
 * @property CarbonImmutable|null $execution_deadline_at
 * @property CarbonImmutable|null $cancel_requested_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 */
#[Fillable(['media_transcode_id', 'status', 'verdict', 'phase', 'request_token', 'attempt_token', 'transcode_attempt_token', 'policy', 'execution_limit_seconds', 'threads', 'source_path', 'source_size_bytes', 'output_path', 'output_size_bytes', 'model_sha256', 'expected_contract', 'results', 'binary_versions', 'savings_percent', 'vmaf_mean', 'coverage_seconds', 'progress_percent', 'diagnostic_tail', 'error', 'reason', 'source_modified_at', 'cancel_requested_at', 'execution_deadline_at', 'started_at', 'finished_at'])]
class MediaQualityCheck extends Model
{
    /** @use HasFactory<MediaQualityCheckFactory> */
    use HasFactory;

    /** @return BelongsTo<MediaTranscode, $this> */
    public function transcode(): BelongsTo
    {
        return $this->belongsTo(MediaTranscode::class, 'media_transcode_id');
    }

    /** @param Builder<MediaQualityCheck> $query */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereIn('status', [TaskStatus::QUEUED, TaskStatus::RUNNING]);
    }

    /** @param Builder<MediaQualityCheck> $query */
    #[Scope]
    protected function forRequest(Builder $query, int $id, string $token): void
    {
        $query->whereKey($id)->where('request_token', $token);
    }

    protected function casts(): array
    {
        return ['status' => TaskStatus::class, 'verdict' => QualityVerdict::class, 'policy' => 'array', 'expected_contract' => 'array',
            'results' => 'array', 'binary_versions' => 'array', 'execution_limit_seconds' => 'integer', 'threads' => 'integer',
            'source_size_bytes' => 'integer', 'output_size_bytes' => 'integer', 'source_modified_at' => 'immutable_datetime',
            'cancel_requested_at' => 'immutable_datetime', 'execution_deadline_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime', 'savings_percent' => 'float',
            'vmaf_mean' => 'float', 'coverage_seconds' => 'float', 'progress_percent' => 'float'];
    }
}
