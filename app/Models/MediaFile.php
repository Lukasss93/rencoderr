<?php

namespace App\Models;

use App\Library\Enums\MediaFileStatus;
use App\Tasks\Enums\TaskStatus;
use Carbon\CarbonImmutable;
use Database\Factories\MediaFileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Number;

/**
 * @property MediaFileStatus $status
 * @property CarbonImmutable $filesystem_modified_at
 * @property CarbonImmutable $first_seen_at
 * @property CarbonImmutable $last_seen_at
 */
#[Fillable(['library_path_id', 'relative_path', 'path_hash', 'extension', 'size_bytes', 'filesystem_modified_at', 'first_seen_at', 'last_seen_at', 'last_seen_scan_token', 'status'])]
class MediaFile extends Model
{
    /** @use HasFactory<MediaFileFactory> */
    use HasFactory;

    /** @return BelongsTo<LibraryPath, $this> */
    public function libraryPath(): BelongsTo
    {
        return $this->belongsTo(LibraryPath::class);
    }

    /** @return HasOne<MediaProbe, $this> */
    public function probe(): HasOne
    {
        return $this->hasOne(MediaProbe::class);
    }

    public function hasCurrentProbe(): bool
    {
        $probe = $this->probe;

        return $this->status === MediaFileStatus::PRESENT && $probe !== null
            && $probe->status === TaskStatus::COMPLETED && $probe->matches($this);
    }

    public function formattedSize(): string
    {
        return Number::fileSize($this->size_bytes, maxPrecision: 2);
    }

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'status' => MediaFileStatus::class,
            'filesystem_modified_at' => 'immutable_datetime',
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
        ];
    }
}
