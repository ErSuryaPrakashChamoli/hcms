<?php

namespace Database\Factories;

use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'is_platform_admin' => false,
            'status' => UserStatus::Active,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn () => ['tenant_id' => $tenant->id]);
    }

    public function platformAdmin(): static
    {
        return $this->state(fn () => ['tenant_id' => null, 'is_platform_admin' => true]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => UserStatus::Suspended]);
    }
}
