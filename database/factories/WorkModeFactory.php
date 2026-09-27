<?php

namespace Database\Factories;

use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Organisation\Models\WorkMode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WorkMode>
 */
class WorkModeFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => $name,
            'code' => Str::upper(Str::random(6)),
            'description' => fake()->optional()->sentence(),
            'status' => ActiveStatus::Active,
        ];
    }
}
