<?php

namespace App\Models;

use App\Tasks\Enums\TaskStatus;
use Carbon\CarbonImmutable;
use Database\Factories\TranscodeRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property TaskStatus $status
 * @property CarbonImmutable|null $cancel_requested_at
 * @property CarbonImmutable|null $enumeration_finished_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 */
#[Fillable(['library_id', 'retry_of_run_id', 'request_token', 'status', 'library_name', 'inventory_cutoff_id', 'inventory_cursor_id', 'excluded_metadata_count', 'retained_output_count', 'error', 'enumeration_finished_at', 'cancel_requested_at', 'started_at', 'finished_at'])]
class TranscodeRun extends Model
{
    /** @use HasFactory<TranscodeRunFactory> */
    use HasFactory;

    /** @return BelongsTo<Library, $this> */
    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class);
    }

    /** @return BelongsTo<TranscodeRun, $this> */
    public function retryOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retry_of_run_id');
    }

    /** @return HasMany<MediaTranscode, $this> */
    public function transcodes(): HasMany
    {
        return $this->hasMany(MediaTranscode::class);
    }

    /** @param Builder<TranscodeRun> $query */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereIn('status', [TaskStatus::PENDING, TaskStatus::QUEUED, TaskStatus::RUNNING]);
    }

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'inventory_cutoff_id' => 'integer', 'inventory_cursor_id' => 'integer',
            'excluded_metadata_count' => 'integer', 'retained_output_count' => 'integer',
            'cancel_requested_at' => 'immutable_datetime', 'enumeration_finished_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime',
        ];
    }
}
