<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Department;
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
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => UserRole::SystemAdmin,
            'department_id' => null,
            'is_active' => true,
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
     * A department chair, scoped to the given (or a new) department.
     */
    public function chair(?Department $department = null): static
    {
        return $this->state(fn () => [
            'role' => UserRole::DepartmentChair,
            'department_id' => ($department ?? Department::factory()->create())->id,
        ]);
    }

    /**
     * An academic coordinator (no department scope).
     */
    public function coordinator(): static
    {
        return $this->state(fn () => [
            'role' => UserRole::AcademicCoordinator,
            'department_id' => null,
        ]);
    }
}
