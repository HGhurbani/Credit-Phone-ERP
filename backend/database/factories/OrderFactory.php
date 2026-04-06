<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'order_number' => 'ORD-'.fake()->unique()->numerify('########'),
            'type' => 'installment',
            'status' => 'draft',
            'subtotal' => 1000,
            'discount_amount' => 0,
            'total' => 1000,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Order $order) {
            if (! $order->tenant_id) {
                $order->tenant_id = Tenant::factory()->create()->id;
            }
            if (! $order->branch_id) {
                $order->branch_id = Branch::factory()->create(['tenant_id' => $order->tenant_id])->id;
            }
            if (! $order->customer_id) {
                $order->customer_id = Customer::factory()->create([
                    'tenant_id' => $order->tenant_id,
                    'branch_id' => $order->branch_id,
                ])->id;
            }
        });
    }

    public function approvedInstallment(int $tenantId, int $branchId, int $customerId, float $total = 1000.0): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'customer_id' => $customerId,
            'type' => 'installment',
            'status' => 'approved',
            'subtotal' => $total,
            'total' => $total,
            'discount_amount' => 0,
        ]);
    }
}
