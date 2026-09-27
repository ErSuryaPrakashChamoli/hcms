<?php

namespace Database\Factories;

use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Organisation\Models\ProfitCentre;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProfitCentre>
 */
class ProfitCentreFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'company_id' => null,
            'name' => $name,
            'code' => Str::upper(Str::random(6)),
            'description' => fake()->optional()->sentence(),
            'status' => ActiveStatus::Active,
        ];
    }
}
