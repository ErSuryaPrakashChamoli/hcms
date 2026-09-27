<?php

namespace Database\Factories;

use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Organisation\Models\Grade;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Grade>
 */
class GradeFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => $name,
            'code' => Str::upper(Str::random(6)),
            'level_id' => null,
            'rank' => fake()->numberBetween(1, 20),
            'description' => fake()->optional()->sentence(),
            'status' => ActiveStatus::Active,
        ];
    }
}
