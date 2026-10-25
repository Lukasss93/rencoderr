<?php

use App\Encoding\Data\MediaSettings;
use App\Models\MediaTranscode;
use App\Tasks\Enums\TaskStatus;
use Illuminate\Support\Str;

function qualityTranscode(): MediaTranscode
{
    $attempt = (string) Str::uuid();
    $item = MediaTranscode::factory()->create(['status' => TaskStatus::COMPLETED, 'attempt_token' => $attempt,
        'output_path' => '1/'.$attempt.'/output.mkv', 'output_size_bytes' => 4]);
    foreach ((new MediaSettings)->toArray() as $key => $value) {
        $item->settings()->create(['key' => 'media.'.$key, 'value' => $value]);
    }

    return $item;
}
