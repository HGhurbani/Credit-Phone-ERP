<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'product_id' => Product::factory(),
            'product_name' => 'Product',
            'product_sku' => 'SKU-1',
            'unit_price' => 1000,
            'quantity' => 1,
            'discount_amount' => 0,
            'total' => 1000,
        ];
    }

    public function forOrderProduct(int $orderId, int $productId, string $productName, float $unitPrice, int $qty = 1): static
    {
        $total = $unitPrice * $qty;

        return $this->state(fn (array $attributes) => [
            'order_id' => $orderId,
            'product_id' => $productId,
            'product_name' => $productName,
            'unit_price' => $unitPrice,
            'quantity' => $qty,
            'total' => $total,
        ]);
    }
}
