<?php

namespace Database\Factories;

use App\Encoding\Enums\ProbeStatus;
use App\Models\MediaFile;
use App\Models\MediaProbe;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MediaProbe> */
class MediaProbeFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'media_file_id' => MediaFile::factory(), 'status' => ProbeStatus::Pending,
            'source_size_bytes' => 1073741824, 'source_modified_at' => now(), 'attempts' => 0,
        ];
    }
}
