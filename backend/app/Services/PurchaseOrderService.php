<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Support\TenantBranchScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseOrderService
{
    public function generatePurchaseNumber(int $tenantId): string
    {
        $prefix = 'PO-'.str_pad((string) $tenantId, 3, '0', STR_PAD_LEFT).'-';
        $last = PurchaseOrder::where('tenant_id', $tenantId)
            ->where('purchase_number', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('purchase_number');
        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<string, mixed>  $data  validated store payload
     */
    public function create(array $data, int $tenantId, int $userId): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $tenantId, $userId) {
            $supplier = Supplier::forTenant($tenantId)->whereKey($data['supplier_id'])->firstOrFail();
            if (! $supplier->is_active) {
                throw ValidationException::withMessages(['supplier_id' => ['Supplier is inactive.']]);
            }

            TenantBranchScope::assertBranchBelongsToTenant((int) $data['branch_id'], $tenantId);
            $branch = Branch::where('id', $data['branch_id'])->where('tenant_id', $tenantId)->firstOrFail();

            $status = $data['status'] ?? 'draft';
            if (! in_array($status, ['draft', 'ordered'], true)) {
                throw ValidationException::withMessages(['status' => ['Invalid status for create.']]);
            }

            $po = PurchaseOrder::create([
                'tenant_id' => $tenantId,
                'supplier_id' => $supplier->id,
                'branch_id' => $branch->id,
                'purchase_number' => $this->generatePurchaseNumber($tenantId),
                'status' => $status,
                'order_date' => $data['order_date'],
                'expected_date' => $data['expected_date'] ?? null,
                'discount_amount' => $data['discount_amount'] ?? 0,
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
                'subtotal' => 0,
                'total' => 0,
            ]);

            $this->syncItems($po, $data['items'] ?? [], $tenantId);
            $this->recalculateTotals($po);

            return $po->fresh(['supplier', 'branch', 'items.product', 'createdBy']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(PurchaseOrder $po, array $data, int $tenantId): PurchaseOrder
    {
        if ($po->status !== 'draft') {
            throw ValidationException::withMessages([
                'purchase_order' => ['Only draft purchase orders can be edited.'],
            ]);
        }

        return DB::transaction(function () use ($po, $data, $tenantId) {
            if (isset($data['supplier_id'])) {
                $supplier = Supplier::forTenant($tenantId)->whereKey($data['supplier_id'])->firstOrFail();
                if (! $supplier->is_active) {
                    throw ValidationException::withMessages(['supplier_id' => ['Supplier is inactive.']]);
                }
                $po->supplier_id = $supplier->id;
            }
            if (isset($data['branch_id'])) {
                TenantBranchScope::assertBranchBelongsToTenant((int) $data['branch_id'], $tenantId);
                $po->branch_id = (int) $data['branch_id'];
            }
            if (array_key_exists('order_date', $data)) {
                $po->order_date = $data['order_date'];
            }
            if (array_key_exists('expected_date', $data)) {
                $po->expected_date = $data['expected_date'];
            }
            if (array_key_exists('discount_amount', $data)) {
                $po->discount_amount = $data['discount_amount'];
            }
            if (array_key_exists('notes', $data)) {
                $po->notes = $data['notes'];
            }

            $po->save();

            if (isset($data['items'])) {
                $this->syncItems($po, $data['items'], $tenantId);
            }

            $this->recalculateTotals($po->fresh());

            return $po->fresh(['supplier', 'branch', 'items.product', 'createdBy']);
        });
    }

    public function updateStatus(PurchaseOrder $po, string $newStatus, int $tenantId): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $newStatus, $tenantId) {
            $allowed = ['draft', 'ordered', 'partially_received', 'received', 'cancelled'];
            if (! in_array($newStatus, $allowed, true)) {
                throw ValidationException::withMessages(['status' => ['Invalid status.']]);
            }

            if ($newStatus === $po->status) {
                return $po->fresh(['supplier', 'branch', 'items.product', 'createdBy']);
            }

            if ($newStatus === 'ordered') {
                if ($po->status !== 'draft') {
                    throw ValidationException::withMessages(['status' => ['Only draft orders can be marked as ordered.']]);
                }
                if ($po->items()->count() === 0) {
                    throw ValidationException::withMessages(['status' => ['Add line items before ordering.']]);
                }
                $po->update(['status' => 'ordered']);

                return $po->fresh(['supplier', 'branch', 'items.product', 'createdBy']);
            }

            if ($newStatus === 'cancelled') {
                if (in_array($po->status, ['received', 'cancelled'], true)) {
                    throw ValidationException::withMessages(['status' => ['This purchase order cannot be cancelled.']]);
                }
                if ($po->goodsReceipts()->exists()) {
                    throw ValidationException::withMessages(['status' => ['Cannot cancel: goods have already been received.']]);
                }
                $po->update(['status' => 'cancelled']);

                return $po->fresh(['supplier', 'branch', 'items.product', 'createdBy']);
            }

            throw ValidationException::withMessages([
                'status' => ['Status can only be set to ordered or cancelled via this action.'],
            ]);
        });
    }

    public function deleteIfAllowed(PurchaseOrder $po): void
    {
        if ($po->status !== 'draft') {
            throw ValidationException::withMessages([
                'purchase_order' => ['Only draft purchase orders can be deleted.'],
            ]);
        }
        if ($po->goodsReceipts()->exists()) {
            throw ValidationException::withMessages([
                'purchase_order' => ['Cannot delete: receipts exist.'],
            ]);
        }
        $po->items()->delete();
        $po->delete();
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function syncItems(PurchaseOrder $po, array $items, int $tenantId): void
    {
        $po->items()->delete();

        foreach ($items as $row) {
            $product = Product::forTenant($tenantId)->whereKey($row['product_id'])->firstOrFail();
            $qty = (int) $row['quantity'];
            $unitCost = (string) $row['unit_cost'];
            $lineTotal = round($qty * (float) $unitCost, 2);

            PurchaseOrderItem::create([
                'purchase_order_id' => $po->id,
                'product_id' => $product->id,
                'quantity' => $qty,
                'unit_cost' => $unitCost,
                'total' => $lineTotal,
            ]);
        }
    }

    private function recalculateTotals(PurchaseOrder $po): void
    {
        $subtotal = (float) $po->items()->sum('total');
        $discount = (float) $po->discount_amount;
        $total = max(0, round($subtotal - $discount, 2));

        $po->update([
            'subtotal' => round($subtotal, 2),
            'total' => $total,
        ]);
    }
}
