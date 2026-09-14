<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        return [
            'name'      => fake()->company(),
            'name_ar'   => null,
            'currency'  => 'SAR',
            'is_active' => true,
        ];
    }

    /**
     * A company a super admin has switched off, or whose subscription
     * has lapsed — nobody in it may sign in. See
     * User::accessDenialReason().
     */
    public function suspended(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
