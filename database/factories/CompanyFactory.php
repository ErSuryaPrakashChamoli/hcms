<?php

namespace Database\Factories;

use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Organisation\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'code' => Str::upper(Str::random(6)),
            'legal_name' => $name.' Pvt Ltd',
            'status' => ActiveStatus::Active,
            'country_code' => 'IN',
            'timezone' => 'Asia/Kolkata',
            'currency' => 'INR',
            'effective_from' => now()->subYear()->toDateString(),
        ];
    }
}
