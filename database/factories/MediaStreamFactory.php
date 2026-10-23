<?php

namespace Database\Factories;

use App\Models\MediaProbe;
use App\Models\MediaStream;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MediaStream> */
class MediaStreamFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['media_probe_id' => MediaProbe::factory(), 'stream_index' => 0, 'codec_type' => 'video', 'codec_name' => 'h264'];
    }
}
