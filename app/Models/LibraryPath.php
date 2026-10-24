<?php

namespace App\Models;

use App\Jobs\ScanLibraryPath;
use App\Tasks\Enums\TaskStatus;
use Carbon\CarbonImmutable;
use Database\Factories\LibraryPathFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
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

    /** Only updates the folder if no other request or job has changed it since it was read. */
    public function queueScan(string $token): bool
    {
        return self::query()->whereKey($this->id)
            ->where('scan_status', $this->scan_status)
            ->where('scan_token', $this->scan_token)
            ->update([
                'scan_status' => TaskStatus::QUEUED,
                'scan_token' => $token,
                'scan_requested_at' => now(),
                'scan_started_at' => null,
                'scan_finished_at' => null,
                'scan_error' => null,
            ]) === 1;
    }

    public static function claimScan(int $pathId, string $token): bool
    {
        return self::query()->scanRequest($pathId, $token)->where('scan_status', TaskStatus::QUEUED)
            ->update(['scan_status' => TaskStatus::RUNNING, 'scan_started_at' => now()]) === 1;
    }

    public static function completeScan(int $pathId, string $token): bool
    {
        return self::query()->runningScan($pathId, $token)->update([
            'scan_status' => TaskStatus::COMPLETED,
            'scan_finished_at' => now(),
            'last_successful_scan_at' => now(),
            'scan_error' => null,
        ]) === 1;
    }

    public static function failScan(int $pathId, string $token, string $error): void
    {
        self::query()->scanRequest($pathId, $token)->whereIn('scan_status', [TaskStatus::QUEUED, TaskStatus::RUNNING])->update([
            'scan_status' => TaskStatus::FAILED,
            'scan_finished_at' => now(),
            'scan_error' => $error,
        ]);
    }

    /** @param Builder<LibraryPath> $query */
    #[Scope]
    protected function scanRequest(Builder $query, int $pathId, string $token): void
    {
        $query->whereKey($pathId)->where('scan_token', $token);
    }

    /** @param Builder<LibraryPath> $query */
    #[Scope]
    protected function runningScan(Builder $query, int $pathId, string $token): void
    {
        $query->scanRequest($pathId, $token)->where('scan_status', TaskStatus::RUNNING);
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
