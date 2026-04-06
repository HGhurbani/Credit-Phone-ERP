<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Inventory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderService
{
    public function create(array $data, int $tenantId, int $branchId, int $userId): Order
    {
        return DB::transaction(function () use ($data, $tenantId, $branchId, $userId) {
            $subtotal = 0;
            $itemsData = [];

            foreach ($data['items'] as $item) {
                $product = Product::findOrFail($item['product_id']);
                $price = $data['type'] === 'installment' ? $product->installment_price : $product->cash_price;
                $itemTotal = ($price * $item['quantity']) - ($item['discount_amount'] ?? 0);
                $subtotal += $itemTotal;

                $itemsData[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_sku' => $product->sku,
                    'unit_price' => $price,
                    'quantity' => $item['quantity'],
                    'discount_amount' => $item['discount_amount'] ?? 0,
                    'total' => $itemTotal,
                    'serial_number' => $item['serial_number'] ?? null,
                ];
            }

            $discountAmount = $data['discount_amount'] ?? 0;
            $total = $subtotal - $discountAmount;

            $order = Order::create([
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'customer_id' => $data['customer_id'],
                'sales_agent_id' => $userId,
                'order_number' => $this->generateOrderNumber($tenantId),
                'type' => $data['type'],
                'status' => 'draft',
                'subtotal' => $subtotal,
                'discount_amount' => $discountAmount,
                'total' => $total,
                'notes' => $data['notes'] ?? null,
            ]);

            $order->items()->createMany($itemsData);

            return $order->load(['items.product', 'customer']);
        });
    }

    public function approve(Order $order, int $userId): Order
    {
        $order->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $userId,
        ]);

        if ($order->isCash()) {
            $this->deductStock($order);
            $order->update(['status' => 'completed']);
        }

        return $order->fresh();
    }

    public function reject(Order $order, string $reason): Order
    {
        $order->update([
            'status' => 'rejected',
            'rejection_reason' => $reason,
        ]);

        return $order->fresh();
    }

    private function deductStock(Order $order): void
    {
        foreach ($order->items as $item) {
            $inventory = Inventory::where('product_id', $item->product_id)
                ->where('branch_id', $order->branch_id)
                ->first();

            if ($inventory) {
                $before = $inventory->quantity;
                $inventory->decrement('quantity', $item->quantity);

                StockMovement::create([
                    'product_id' => $item->product_id,
                    'branch_id' => $order->branch_id,
                    'created_by' => auth()->id(),
                    'type' => 'out',
                    'quantity' => $item->quantity,
                    'quantity_before' => $before,
                    'quantity_after' => $before - $item->quantity,
                    'reference_type' => Order::class,
                    'reference_id' => $order->id,
                    'serial_number' => $item->serial_number,
                ]);
            }
        }
    }

    private function generateOrderNumber(int $tenantId): string
    {
        $prefix = 'ORD-' . str_pad($tenantId, 3, '0', STR_PAD_LEFT) . '-';
        $last = Order::where('tenant_id', $tenantId)
            ->where('order_number', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->value('order_number');

        $next = $last ? (int) substr($last, strlen($prefix)) + 1 : 1;
        return $prefix . str_pad($next, 6, '0', STR_PAD_LEFT);
    }
}
