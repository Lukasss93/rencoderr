<?php

namespace App\Models;

use App\Encoding\Enums\ProbeStatus;
use Carbon\CarbonImmutable;
use Database\Factories\MediaProbeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property ProbeStatus $status
 * @property CarbonImmutable $source_modified_at
 * @property CarbonImmutable|null $started_at
 * @property array<string, mixed>|null $metadata
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ProbeStatus::class,
            'source_size_bytes' => 'integer', 'attempts' => 'integer', 'bit_rate' => 'integer',
            'source_modified_at' => 'immutable_datetime', 'requested_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime', 'probed_at' => 'immutable_datetime',
            'duration_seconds' => 'decimal:6', 'metadata' => 'array',
        ];
    }
}
