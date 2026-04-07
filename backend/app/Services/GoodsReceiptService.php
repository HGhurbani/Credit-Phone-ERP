<?php

namespace App\Services;

use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\TenantBranchScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GoodsReceiptService
{
    public function generateReceiptNumber(int $tenantId): string
    {
        $prefix = 'GR-'.str_pad((string) $tenantId, 3, '0', STR_PAD_LEFT).'-';
        $last = GoodsReceipt::where('tenant_id', $tenantId)
            ->where('receipt_number', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('receipt_number');
        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<int, array{purchase_order_item_id: int, quantity: int}>  $lines
     */
    public function receive(PurchaseOrder $po, array $lines, User $user, ?string $notes = null): GoodsReceipt
    {
        if (! in_array($po->status, ['ordered', 'partially_received'], true)) {
            throw ValidationException::withMessages([
                'purchase_order_id' => ['Goods can only be received when the purchase order is ordered or partially received.'],
            ]);
        }

        if ($lines === []) {
            throw ValidationException::withMessages(['items' => ['Add at least one line to receive.']]);
        }

        TenantBranchScope::assertBranchAccessibleForStock($user, (int) $po->branch_id, (int) $po->tenant_id);

        return DB::transaction(function () use ($po, $lines, $user, $notes) {
            $locked = PurchaseOrder::whereKey($po->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, ['ordered', 'partially_received'], true)) {
                throw ValidationException::withMessages([
                    'purchase_order_id' => ['Purchase order is no longer receivable.'],
                ]);
            }

            $itemsById = $locked->items()->with('product')->get()->keyBy('id');

            $receipt = GoodsReceipt::create([
                'tenant_id' => $locked->tenant_id,
                'purchase_order_id' => $locked->id,
                'branch_id' => $locked->branch_id,
                'receipt_number' => $this->generateReceiptNumber((int) $locked->tenant_id),
                'received_at' => now(),
                'notes' => $notes,
                'received_by' => $user->id,
            ]);

            foreach ($lines as $line) {
                $itemId = (int) $line['purchase_order_item_id'];
                $qty = (int) $line['quantity'];

                if ($qty < 1) {
                    throw ValidationException::withMessages([
                        'items' => ['Each line must have a positive quantity.'],
                    ]);
                }

                /** @var PurchaseOrderItem|null $poItem */
                $poItem = $itemsById->get($itemId);
                if ($poItem === null || (int) $poItem->purchase_order_id !== (int) $locked->id) {
                    throw ValidationException::withMessages([
                        'items' => ['Invalid purchase order line.'],
                    ]);
                }

                $already = (int) GoodsReceiptItem::where('purchase_order_item_id', $poItem->id)
                    ->sum('quantity');

                $ordered = (int) $poItem->quantity;
                if ($already + $qty > $ordered) {
                    throw ValidationException::withMessages([
                        'items' => ['Cannot receive more than ordered for one or more lines.'],
                    ]);
                }

                GoodsReceiptItem::create([
                    'goods_receipt_id' => $receipt->id,
                    'purchase_order_item_id' => $poItem->id,
                    'quantity' => $qty,
                ]);

                $this->applyStockIn($poItem, $qty, $locked->branch_id, $user->id, $receipt->id);
            }

            $this->syncPurchaseOrderStatus($locked->fresh());

            return $receipt->load(['items.purchaseOrderItem.product', 'receivedBy', 'branch']);
        });
    }

    public function syncPurchaseOrderStatus(PurchaseOrder $po): void
    {
        $po->load('items');
        if ($po->items->isEmpty()) {
            return;
        }

        $allReceived = true;
        $hasAny = false;
        foreach ($po->items as $item) {
            $sum = (int) GoodsReceiptItem::where('purchase_order_item_id', $item->id)->sum('quantity');
            if ($sum > 0) {
                $hasAny = true;
            }
            if ($sum < (int) $item->quantity) {
                $allReceived = false;
            }
        }

        if ($allReceived) {
            $po->update(['status' => 'received']);
        } elseif ($hasAny) {
            $po->update(['status' => 'partially_received']);
        }
    }

    private function applyStockIn(PurchaseOrderItem $lineItem, int $qty, int $branchId, int $userId, int $goodsReceiptId): void
    {
        $product = Product::whereKey($lineItem->product_id)->firstOrFail();

        $inventory = Inventory::firstOrCreate(
            ['product_id' => $product->id, 'branch_id' => $branchId],
            ['quantity' => 0]
        );

        $before = (int) $inventory->quantity;
        $inventory->increment('quantity', $qty);
        $inventory->refresh();
        $after = (int) $inventory->quantity;

        StockMovement::create([
            'product_id' => $product->id,
            'branch_id' => $branchId,
            'created_by' => $userId,
            'type' => 'in',
            'quantity' => $qty,
            'quantity_before' => $before,
            'quantity_after' => $after,
            'reference_type' => GoodsReceipt::class,
            'reference_id' => $goodsReceiptId,
            'notes' => 'Purchase receipt',
        ]);
    }
}
