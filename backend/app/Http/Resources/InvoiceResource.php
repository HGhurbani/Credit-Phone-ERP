<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'type' => $this->type,
            'status' => $this->status,
            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discount_amount,
            'total' => $this->total,
            'paid_amount' => $this->paid_amount,
            'remaining_amount' => $this->remaining_amount,
            'issue_date' => $this->issue_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'notes' => $this->notes,
            'customer' => $this->whenLoaded('customer', fn() => ['id' => $this->customer->id, 'name' => $this->customer->name, 'phone' => $this->customer->phone]),
            'branch' => $this->whenLoaded('branch', fn() => ['id' => $this->branch->id, 'name' => $this->branch->name]),
            'order' => $this->whenLoaded('order', fn() => $this->order ? ['id' => $this->order->id, 'order_number' => $this->order->order_number] : null),
            'contract' => $this->whenLoaded('contract', fn() => $this->contract ? ['id' => $this->contract->id, 'contract_number' => $this->contract->contract_number] : null),
            'items' => $this->whenLoaded('items'),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($p) => [
                'id' => $p->id,
                'receipt_number' => $p->receipt_number,
                'amount' => $p->amount,
                'payment_method' => $p->payment_method,
                'payment_date' => $p->payment_date?->toDateString(),
                'reference_number' => $p->reference_number,
                'collector_notes' => $p->collector_notes,
                'collected_by' => $p->relationLoaded('collectedBy') && $p->collectedBy ? [
                    'id' => $p->collectedBy->id,
                    'name' => $p->collectedBy->name,
                ] : null,
                'receipt' => $p->relationLoaded('receipt') ? $p->receipt : null,
            ])->values()),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
