<?php

namespace Database\Factories;

use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Organisation\Models\Designation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Designation>
 */
class DesignationFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => $name,
            'code' => Str::upper(Str::random(6)),
            'level_id' => null,
            'grade_id' => null,
            'job_family_id' => null,
            'department_id' => null,
            'default_reporting_level_id' => null,
            'description' => fake()->optional()->sentence(),
            'status' => ActiveStatus::Active,
        ];
    }
}
