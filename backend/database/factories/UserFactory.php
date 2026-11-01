<?php

namespace Database\Factories;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+23480'.fake()->unique()->numerify('########'),
            'password' => 'password123',
            'email_verified_at' => now(),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }

    public function withoutPassword(): static
    {
        return $this->state(fn () => ['password' => null]);
    }

    public function status(UserStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function withRole(RoleName ...$roles): static
    {
        return $this->afterCreating(function (User $user) use ($roles): void {
            foreach ($roles as $role) {
                $user->assignRole($role);
            }
        });
    }
}
