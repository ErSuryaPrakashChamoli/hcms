<?php

namespace Database\Factories;

use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Organisation\Enums\LocationType;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'company_id' => Company::factory(),
            'name' => $name,
            'code' => Str::upper(Str::random(6)),
            'type' => LocationType::Office,
            'address_line_1' => null,
            'address_line_2' => null,
            'city' => fake()->city(),
            'state_code' => null,
            'postal_code' => null,
            'country_code' => 'IN',
            'timezone' => null,
            'status' => ActiveStatus::Active,
        ];
    }
}
