<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'branch_id' => null,
            'name' => fake()->name(),
            'phone' => fake()->unique()->numerify('##########'),
            'is_active' => true,
        ];
    }

    public function forTenantBranch(int $tenantId, ?int $branchId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
        ]);
    }
}
