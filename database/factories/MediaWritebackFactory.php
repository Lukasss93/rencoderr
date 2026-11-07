<?php

namespace Database\Factories;

use App\Models\MediaQualityCheck;
use App\Models\MediaWriteback;
use App\Tasks\Enums\TaskStatus;
use App\Writeback\Enums\WritebackMode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<MediaWriteback> */
class MediaWritebackFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'media_quality_check_id' => MediaQualityCheck::factory(),
            'media_transcode_id' => fn (array $attributes): int => MediaQualityCheck::query()->whereKey($attributes['media_quality_check_id'])->firstOrFail()->media_transcode_id,
            'status' => TaskStatus::QUEUED, 'mode' => WritebackMode::APPROVAL, 'phase' => 'copying',
            'request_token' => (string) Str::uuid(),
            'source_path' => '/media/movie.mkv', 'staging_path' => '/staging/output.mkv',
            'candidate_path' => '/media/movie.rencoderr-'.Str::uuid().'.pending.mkv',
            'destination_path' => '/media/movie.mkv', 'backup_path' => '/media/movie.'.Str::uuid().'.backup',
            'source_fingerprint' => ['dev' => 1, 'ino' => 1, 'size' => 5, 'mtime' => 1, 'ctime' => 1, 'sha256' => hash('sha256', 'video')],
            'output_fingerprint' => ['dev' => 1, 'ino' => 2, 'size' => 3, 'mtime' => 1, 'ctime' => 1, 'sha256' => hash('sha256', 'new')],
            'comparison' => [],
        ];
    }
}
