<?php

namespace Database\Factories;

use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\People\Models\Skill;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Skill>
 */
class SkillFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'name' => ucfirst($name),
            'code' => Str::upper(Str::slug($name, '_')).'_'.Str::upper(Str::random(3)),
            'category' => 'general',
            'status' => ActiveStatus::Active,
        ];
    }
}
