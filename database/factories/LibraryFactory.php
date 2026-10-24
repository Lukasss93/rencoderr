<?php

namespace Database\Factories;

use App\Models\Library;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Library> */
class LibraryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'automatic_scanning' => true,
            'scan_interval_minutes' => 15,
            'exclusion_patterns' => [],
        ];
    }

    public function manual(): static
    {
        return $this->state(fn (): array => ['automatic_scanning' => false]);
    }
}
