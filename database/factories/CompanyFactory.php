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
            // Sells goods AND services, so both item lines and
            // service (no-item) sale lines are allowed — see
            // GuardsStockLevels::rejectLinesWithoutItem().
            'business_types' => ['service', 'trading'],
        ];
    }

    /** A company that sells goods only — every sale line needs an item. */
    public function goodsOnly(): static
    {
        return $this->state(fn () => ['business_types' => ['trading']]);
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
