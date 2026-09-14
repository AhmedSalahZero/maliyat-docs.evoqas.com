<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
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

    // ── Maliyat Docs roles ────────────────────────────────────────
    // users.role defaults to 'employee' at the database level, and
    // company_id is filled in by whichever state is applied, so a
    // bare User::factory() is intentionally NOT a usable app user —
    // pick a role state so the tenant boundary is always explicit.

    /**
     * Platform owner. Has no company — they sit above the tenant
     * boundary, which is what lets them browse every company.
     */
    public function superAdmin(): static
    {
        return $this->state(fn () => [
            'role'       => \App\Enums\UserRole::SuperAdmin->value,
            'company_id' => null,
        ]);
    }

    public function companyAdmin(?\App\Models\Company $company = null): static
    {
        return $this->state(fn () => [
            'role'       => \App\Enums\UserRole::CompanyAdmin->value,
            'company_id' => ($company ?? \App\Models\Company::factory()->create())->id,
        ]);
    }

    public function employee(?\App\Models\Company $company = null): static
    {
        return $this->state(fn () => [
            'role'       => \App\Enums\UserRole::Employee->value,
            'company_id' => ($company ?? \App\Models\Company::factory()->create())->id,
        ]);
    }

    /**
     * Individually switched off by a company admin — distinct from
     * the whole company being suspended.
     */
    public function suspended(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
