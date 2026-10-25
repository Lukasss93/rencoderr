<?php

namespace Database\Factories;

use App\Models\MediaFile;
use App\Models\MediaTranscode;
use App\Models\TranscodeRun;
use App\Tasks\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<MediaTranscode> */
class MediaTranscodeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'transcode_run_id' => TranscodeRun::factory(), 'media_file_id' => MediaFile::factory(),
            'request_token' => (string) Str::uuid(), 'status' => TaskStatus::QUEUED,
            'source_path' => '/media/movie.mkv', 'source_size_bytes' => 5, 'source_modified_at' => now(),
        ];
    }
}
