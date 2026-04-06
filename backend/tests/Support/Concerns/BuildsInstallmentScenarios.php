<?php

namespace Tests\Support\Concerns;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Tenant;

trait BuildsInstallmentScenarios
{
    /**
     * Approved installment order with one line item, inventory row, active tenant.
     *
     * @return array{tenant: Tenant, branch: Branch, customer: Customer, product: Product, order: Order, inventory: Inventory}
     */
    protected function createApprovedInstallmentOrderWithInventory(
        float $cashPrice = 1000.0,
        int $inventoryQty = 10
    ): array {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
        ]);
        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'cash_price' => $cashPrice,
        ]);
        $inventory = Inventory::create([
            'product_id' => $product->id,
            'branch_id' => $branch->id,
            'quantity' => $inventoryQty,
        ]);
        $order = Order::factory()->approvedInstallment($tenant->id, $branch->id, $customer->id, $cashPrice)->create();
        OrderItem::factory()->forOrderProduct(
            $order->id,
            $product->id,
            $product->name,
            $cashPrice,
            1
        )->create();

        return compact('tenant', 'branch', 'customer', 'product', 'order', 'inventory');
    }

    /**
     * @return array{customer: Customer, product: Product, order: Order, inventory: Inventory}
     */
    protected function createApprovedInstallmentOrderForTenantBranch(
        Tenant $tenant,
        Branch $branch,
        float $cashPrice = 1000.0,
        int $inventoryQty = 10
    ): array {
        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
        ]);
        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'cash_price' => $cashPrice,
        ]);
        $inventory = Inventory::create([
            'product_id' => $product->id,
            'branch_id' => $branch->id,
            'quantity' => $inventoryQty,
        ]);
        $order = Order::factory()->approvedInstallment($tenant->id, $branch->id, $customer->id, $cashPrice)->create();
        OrderItem::factory()->forOrderProduct(
            $order->id,
            $product->id,
            $product->name,
            $cashPrice,
            1
        )->create();

        return compact('customer', 'product', 'order', 'inventory');
    }

    /**
     * Payload matching percentage-mode pricing (default 5% monthly × 12 months).
     */
    protected function validContractPayloadForOrder(Order $order, int $durationMonths = 12): array
    {
        $order->load('items.product');
        $cashTotal = (float) $order->items->sum(fn ($i) => (float) $i->product->cash_price * $i->quantity);
        $defaultPercent = 5.0;
        $monthlyAmount = round($cashTotal * ($defaultPercent / 100), 2);
        $financedAmount = round($monthlyAmount * $durationMonths, 2);
        $downPayment = round($cashTotal - $financedAmount, 2);

        return [
            'order_id' => $order->id,
            'down_payment' => $downPayment,
            'duration_months' => $durationMonths,
            'start_date' => now()->toDateString(),
            'first_due_date' => now()->addMonth()->toDateString(),
        ];
    }
}
