<?php

namespace Database\Factories;

use App\Encoding\Data\MediaSettings;
use App\Models\MediaQualityCheck;
use App\Models\MediaTranscode;
use App\QualityControl\QualityPolicy;
use App\Tasks\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<MediaQualityCheck> */
class MediaQualityCheckFactory extends Factory
{
    public function definition(): array
    {
        $attempt = (string) Str::uuid();

        return [
            'media_transcode_id' => MediaTranscode::factory()->state(['status' => TaskStatus::COMPLETED,
                'attempt_token' => $attempt, 'output_size_bytes' => 4, 'output_path' => 'fixture/output.mkv']),
            'status' => TaskStatus::QUEUED, 'request_token' => (string) Str::uuid(),
            'transcode_attempt_token' => $attempt, 'execution_limit_seconds' => 86400, 'threads' => 2,
            'source_path' => '/media/movie.mkv', 'source_size_bytes' => 5, 'source_modified_at' => now(),
            'output_path' => 'fixture/output.mkv', 'output_size_bytes' => 4,
            'policy' => fn (array $attributes): array => [
                ...app(QualityPolicy::class)->snapshot(MediaTranscode::query()->whereKey($attributes['media_transcode_id'])->firstOrFail()),
                'media' => (new MediaSettings)->toArray(),
            ],
        ];
    }
}
