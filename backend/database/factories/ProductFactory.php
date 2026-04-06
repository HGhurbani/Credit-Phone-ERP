<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->words(3, true),
            'cash_price' => 1000,
            'installment_price' => 1200,
            'min_down_payment' => 0,
            'allowed_durations' => [3, 6, 12, 24],
            'monthly_percent_of_cash' => null,
            'fixed_monthly_amount' => null,
            'is_active' => true,
        ];
    }

    public function forTenant(int $tenantId): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $tenantId,
        ]);
    }
}
