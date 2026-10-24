<?php

namespace App\Models;

use App\Library\Enums\MediaFileStatus;
use App\Tasks\Enums\TaskStatus;
use Carbon\CarbonImmutable;
use Database\Factories\MediaFileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
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

    /** @param Builder<MediaFile> $query */
    #[Scope]
    protected function search(Builder $query, string $text): void
    {
        if ($text !== '') {
            $query->whereRaw("relative_path like ? escape '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text).'%']);
        }
    }

    /** @param Builder<MediaFile> $query */
    #[Scope]
    protected function inLibrary(Builder $query, ?int $libraryId): void
    {
        if ($libraryId !== null) {
            $query->whereHas('libraryPath', fn (Builder $paths) => $paths->where('library_id', $libraryId));
        }
    }

    /** @param Builder<MediaFile> $query */
    #[Scope]
    protected function withStatus(Builder $query, ?MediaFileStatus $status): void
    {
        if ($status !== null) {
            $query->where('status', $status);
        }
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
