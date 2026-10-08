<?php

namespace Database\Factories;

use App\Domain\Identity\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->jobTitle();

        return [
            'name' => $name,
            // roles.slug is varchar(64); long Faker job titles overflowed it on MySQL.
            'slug' => Str::limit(Str::slug($name), 55, '').'-'.Str::lower(Str::random(4)),
            'description' => fake()->sentence(),
            'is_system' => false,
        ];
    }
}
