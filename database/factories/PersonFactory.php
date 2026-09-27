<?php

namespace Database\Factories;

use App\Domain\People\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Person>
 */
class PersonFactory extends Factory
{
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'date_of_birth' => fake()->dateTimeBetween('-55 years', '-21 years')->format('Y-m-d'),
            'gender' => fake()->randomElement(['male', 'female']),
            'nationality' => 'Indian',
            'personal_email' => fake()->unique()->safeEmail(),
            'personal_phone' => fake()->numerify('9#########'),
        ];
    }
}
