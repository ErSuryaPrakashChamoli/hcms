<?php

namespace Database\Factories;

use App\Domain\Platform\Enums\TenantStatus;
use App\Domain\Platform\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'status' => TenantStatus::Active,
            'country_code' => 'IN',
            'timezone' => 'Asia/Kolkata',
            'locale' => 'en',
            'currency' => 'INR',
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => TenantStatus::Suspended]);
    }
}
