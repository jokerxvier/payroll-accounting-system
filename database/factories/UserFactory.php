<?php

declare(strict_types=1);

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
            'full_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            // Default LMS role_id: 4 (Teacher) — an allowlisted "employee" role
            // so the AssignPayrollRoleOnLogin listener falls through to the
            // 'employee' Spatie role on login. Override per-test as needed.
            'role_id' => 4,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Set the LMS role_id on the user (for the role-mapping listener).
     */
    public function withLmsRole(int $roleId): static
    {
        return $this->state(fn (array $attributes) => [
            'role_id' => $roleId,
        ]);
    }
}
