<?php

namespace App\Models;

use App\Jobs\ScanLibraryPath;
use App\Models\Builders\LibraryPathBuilder;
use App\Tasks\Enums\TaskStatus;
use Carbon\CarbonImmutable;
use Database\Factories\LibraryPathFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property TaskStatus $scan_status
 * @property CarbonImmutable|null $scan_requested_at
 * @property CarbonImmutable|null $scan_started_at
 * @property CarbonImmutable|null $scan_finished_at
 * @property CarbonImmutable|null $last_successful_scan_at
 */
#[Fillable(['library_id', 'path', 'path_hash', 'scan_status', 'scan_token', 'scan_requested_at', 'scan_started_at', 'scan_finished_at', 'last_successful_scan_at', 'scan_error'])]
#[UseEloquentBuilder(LibraryPathBuilder::class)]
class LibraryPath extends Model
{
    /** @use HasFactory<LibraryPathFactory> */
    use HasFactory;

    /** @return BelongsTo<Library, $this> */
    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class);
    }

    /** @return HasMany<MediaFile, $this> */
    public function mediaFiles(): HasMany
    {
        return $this->hasMany(MediaFile::class);
    }

    public function hasPendingScan(): bool
    {
        if (! $this->scan_status->isActive()) {
            return false;
        }

        $lastProgressAt = $this->scan_started_at ?? $this->scan_requested_at;

        return $lastProgressAt?->addSeconds(ScanLibraryPath::TIMEOUT_SECONDS)->isFuture() ?? false;
    }

    protected function casts(): array
    {
        return [
            'scan_status' => TaskStatus::class,
            'scan_requested_at' => 'immutable_datetime',
            'scan_started_at' => 'immutable_datetime',
            'scan_finished_at' => 'immutable_datetime',
            'last_successful_scan_at' => 'immutable_datetime',
        ];
    }
}
