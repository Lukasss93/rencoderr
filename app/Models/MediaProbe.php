<?php

namespace App\Models;

use App\Tasks\Enums\TaskStatus;
use Carbon\CarbonImmutable;
use Database\Factories\MediaProbeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A request token identifies one probe request and an attempt token one run of it, so a worker
 * that lost its claim cannot overwrite the work of the one that replaced it.
 *
 * @property TaskStatus $status
 * @property CarbonImmutable $source_modified_at
 */
#[Fillable(['media_file_id', 'status', 'request_token', 'attempt_token', 'source_size_bytes', 'source_modified_at', 'attempts', 'requested_at', 'started_at', 'finished_at', 'probed_at', 'error', 'format', 'duration_seconds', 'bit_rate', 'metadata'])]
class MediaProbe extends Model
{
    /** @use HasFactory<MediaProbeFactory> */
    use HasFactory;

    /** @return BelongsTo<MediaFile, $this> */
    public function mediaFile(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class);
    }

    /** @return HasMany<MediaStream, $this> */
    public function streams(): HasMany
    {
        return $this->hasMany(MediaStream::class)->orderBy('stream_index');
    }

    public function matches(MediaFile $file): bool
    {
        return $this->source_size_bytes === $file->size_bytes
            && $this->source_modified_at->equalTo($file->filesystem_modified_at);
    }

    /** @return array<string, int|string|null> */
    public static function pendingAttributes(): array
    {
        return [
            'status' => TaskStatus::PENDING->value, 'request_token' => null, 'attempt_token' => null,
            'attempts' => 0, 'requested_at' => null, 'started_at' => null, 'finished_at' => null, 'error' => null,
        ];
    }

    /** @param Builder<MediaProbe> $query */
    #[Scope]
    protected function forRequest(Builder $query, int $fileId, string $token): void
    {
        $query->where('media_file_id', $fileId)->where('request_token', $token);
    }

    /** @param Builder<MediaProbe> $query */
    #[Scope]
    protected function claimable(Builder $query, int $staleAfterSeconds): void
    {
        $query->where(function (Builder $query) use ($staleAfterSeconds): void {
            $query->where('status', TaskStatus::QUEUED)->orWhere(function (Builder $query) use ($staleAfterSeconds): void {
                $query->where('status', TaskStatus::RUNNING)->where('started_at', '<=', now()->subSeconds($staleAfterSeconds));
            });
        });
    }

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'source_size_bytes' => 'integer', 'attempts' => 'integer', 'bit_rate' => 'integer',
            'source_modified_at' => 'immutable_datetime', 'requested_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime', 'probed_at' => 'immutable_datetime',
            'duration_seconds' => 'decimal:6', 'metadata' => 'array',
        ];
    }
}
