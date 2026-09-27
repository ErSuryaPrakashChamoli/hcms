<?php

namespace Database\Factories;

use App\Domain\Employment\Models\Employee;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\People\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'person_id' => Person::factory(),
            'employee_code' => 'EMP'.Str::upper(Str::random(6)),
            'lifecycle_state' => LifecycleState::Active,
            'joining_date' => fake()->dateTimeBetween('-5 years', '-1 month')->format('Y-m-d'),
            'work_email' => fake()->unique()->companyEmail(),
        ];
    }
}
