<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Receipt;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    public function createFromOrder(Order $order): Invoice
    {
        $items = $order->items->map(fn($item) => [
            'description' => $item->product_name . ($item->product_sku ? " ({$item->product_sku})" : ''),
            'quantity' => $item->quantity,
            'unit_price' => $item->unit_price,
            'total' => $item->total,
        ])->toArray();

        $invoice = Invoice::create([
            'tenant_id' => $order->tenant_id,
            'branch_id' => $order->branch_id,
            'customer_id' => $order->customer_id,
            'order_id' => $order->id,
            'invoice_number' => $this->generateInvoiceNumber($order->tenant_id),
            'type' => $order->type === 'cash' ? 'cash' : 'installment',
            'status' => 'unpaid',
            'subtotal' => $order->subtotal,
            'discount_amount' => $order->discount_amount,
            'total' => $order->total,
            'paid_amount' => 0,
            'remaining_amount' => $order->total,
            'issue_date' => today(),
            'due_date' => $order->type === 'cash' ? today() : null,
        ]);

        $invoice->items()->createMany($items);

        return $invoice->load(['customer', 'order', 'items']);
    }

    public function markPaid(Invoice $invoice, float $amount): Invoice
    {
        $newPaid = $invoice->paid_amount + $amount;
        $newRemaining = max(0, $invoice->total - $newPaid);
        $status = $newRemaining <= 0 ? 'paid' : 'partial';

        $invoice->update([
            'paid_amount' => $newPaid,
            'remaining_amount' => $newRemaining,
            'status' => $status,
        ]);

        return $invoice->fresh();
    }

    /**
     * تسجيل دفعة على فاتورة (كاش أو جزء من مبلغ) وتحديث الحالة unpaid → partial → paid
     */
    public function recordPayment(Invoice $invoice, array $data, int $userId): Payment
    {
        if ($invoice->status === 'cancelled') {
            throw new \InvalidArgumentException('Cannot record payment on a cancelled invoice.');
        }
        if ((float) $invoice->remaining_amount <= 0) {
            throw new \InvalidArgumentException('Invoice is already fully paid.');
        }

        $requested = (float) $data['amount'];
        $amount = min($requested, (float) $invoice->remaining_amount);
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Payment amount must be greater than zero.');
        }

        return DB::transaction(function () use ($invoice, $data, $userId, $amount) {
            $this->markPaid($invoice, $amount);
            $invoice->refresh();

            $payment = Payment::create([
                'tenant_id' => $invoice->tenant_id,
                'branch_id' => $invoice->branch_id,
                'customer_id' => $invoice->customer_id,
                'contract_id' => null,
                'schedule_id' => null,
                'invoice_id' => $invoice->id,
                'collected_by' => $userId,
                'receipt_number' => $this->generateReceiptNumber($invoice->tenant_id),
                'amount' => $amount,
                'payment_method' => $data['payment_method'] ?? 'cash',
                'payment_date' => $data['payment_date'] ?? today(),
                'reference_number' => $data['reference_number'] ?? null,
                'collector_notes' => $data['collector_notes'] ?? null,
            ]);

            Receipt::create([
                'payment_id' => $payment->id,
                'tenant_id' => $invoice->tenant_id,
                'receipt_number' => $payment->receipt_number,
            ]);

            return $payment->load(['customer', 'branch', 'collectedBy', 'receipt', 'invoice']);
        });
    }

    /**
     * إلغاء فاتورة غير المسددة بالكامل
     */
    public function cancel(Invoice $invoice): Invoice
    {
        if ($invoice->status === 'paid') {
            throw new \InvalidArgumentException('Cannot cancel a fully paid invoice.');
        }
        if ($invoice->status === 'cancelled') {
            return $invoice;
        }

        $invoice->update(['status' => 'cancelled']);

        return $invoice->fresh();
    }

    private function generateInvoiceNumber(int $tenantId): string
    {
        $prefix = 'INV-' . str_pad($tenantId, 3, '0', STR_PAD_LEFT) . '-';
        $last = Invoice::where('tenant_id', $tenantId)
            ->where('invoice_number', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->value('invoice_number');

        $next = $last ? (int) substr($last, strlen($prefix)) + 1 : 1;
        return $prefix . str_pad($next, 6, '0', STR_PAD_LEFT);
    }

    private function generateReceiptNumber(int $tenantId): string
    {
        $prefix = 'REC-' . str_pad($tenantId, 3, '0', STR_PAD_LEFT) . '-';
        $last = Payment::where('tenant_id', $tenantId)
            ->where('receipt_number', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->value('receipt_number');

        $next = $last ? (int) substr($last, strlen($prefix)) + 1 : 1;
        return $prefix . str_pad($next, 6, '0', STR_PAD_LEFT);
    }
}
