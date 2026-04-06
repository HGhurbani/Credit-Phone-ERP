<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'tenant_id' => null,
            'branch_id' => null,
            'is_super_admin' => false,
            'is_active' => true,
        ];
    }

    public function superAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_super_admin' => true,
            'tenant_id' => null,
            'branch_id' => null,
        ]);
    }

    public function forTenant(int $tenantId, ?int $branchId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => []);
    }
}
