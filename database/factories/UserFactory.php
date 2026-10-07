<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Guardian;
use App\Models\Teacher;
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
            // Admin needs no linked row, so it is the safe default; use parent() / teacher() for those roles.
            'role' => UserRole::Admin,
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

    public function parent(): static
    {
        return $this->state(fn () => ['role' => UserRole::Parent, 'parent_id' => Guardian::create()->id]);
    }

    public function teacher(): static
    {
        return $this->state(fn () => ['role' => UserRole::Teacher, 'teacher_id' => Teacher::create()->id]);
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => UserRole::Admin]);
    }
}
