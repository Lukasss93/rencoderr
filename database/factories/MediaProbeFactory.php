<?php

namespace Database\Factories;

use App\Models\MediaFile;
use App\Models\MediaProbe;
use App\Tasks\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MediaProbe> */
class MediaProbeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'media_file_id' => MediaFile::factory(), 'status' => TaskStatus::PENDING,
            'source_size_bytes' => 1073741824, 'source_modified_at' => now(), 'attempts' => 0,
        ];
    }
}
