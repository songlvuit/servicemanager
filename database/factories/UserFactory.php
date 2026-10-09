<?php

namespace Database\Factories;

use App\Enums\SystemRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => null,
            'position' => null,
            'is_active' => true,
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Gán vai trò sau khi tạo (vai trò phải có sẵn — chạy RolesAndPermissionsSeeder trước).
     */
    public function withRole(SystemRole|string $role): static
    {
        return $this->afterCreating(function (User $user) use ($role): void {
            $user->assignRole($role instanceof SystemRole ? $role->value : $role);
        });
    }
}
