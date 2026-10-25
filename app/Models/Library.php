<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\LibraryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @property list<string> $exclusion_patterns
 * @property CarbonImmutable|null $last_scan_requested_at
 */
#[Fillable(['name', 'automatic_scanning', 'scan_interval_minutes', 'exclusion_patterns', 'last_scan_requested_at'])]
class Library extends Model
{
    /** @use HasFactory<LibraryFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::deleting(function (Library $library): void {
            $library->settings()->delete();
        });
    }

    /** @return MorphMany<Setting, $this> */
    public function settings(): MorphMany
    {
        return $this->morphMany(Setting::class, 'settingable');
    }

    /** @return HasMany<LibraryPath, $this> */
    public function paths(): HasMany
    {
        return $this->hasMany(LibraryPath::class);
    }

    /** @return HasManyThrough<MediaFile, LibraryPath, $this> */
    public function mediaFiles(): HasManyThrough
    {
        return $this->hasManyThrough(MediaFile::class, LibraryPath::class);
    }

    protected function casts(): array
    {
        return [
            'automatic_scanning' => 'boolean',
            'scan_interval_minutes' => 'integer',
            'exclusion_patterns' => 'array',
            'last_scan_requested_at' => 'immutable_datetime',
        ];
    }
}
