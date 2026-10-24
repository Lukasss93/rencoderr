<?php

namespace Database\Factories;

use App\Models\Library;
use App\Models\TranscodeRun;
use App\Tasks\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<TranscodeRun> */
class TranscodeRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'library_id' => Library::factory(), 'request_token' => (string) Str::uuid(),
            'status' => TaskStatus::PENDING, 'library_name' => 'Movies',
        ];
    }
}
