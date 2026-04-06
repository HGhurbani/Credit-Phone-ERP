<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContractResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contract_number' => $this->contract_number,
            'status' => $this->status,
            'financed_amount' => $this->financed_amount,
            'down_payment' => $this->down_payment,
            'duration_months' => $this->duration_months,
            'monthly_amount' => $this->monthly_amount,
            'total_amount' => $this->total_amount,
            'paid_amount' => $this->paid_amount,
            'remaining_amount' => $this->remaining_amount,
            'start_date' => $this->start_date?->toDateString(),
            'first_due_date' => $this->first_due_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'notes' => $this->notes,
            'customer' => $this->whenLoaded('customer', fn() => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'phone' => $this->customer->phone,
            ]),
            'branch' => $this->whenLoaded('branch', fn() => ['id' => $this->branch->id, 'name' => $this->branch->name]),
            'order' => $this->whenLoaded('order', fn() => $this->order ? ['id' => $this->order->id, 'order_number' => $this->order->order_number, 'total' => $this->order->total] : null),
            'schedules' => $this->whenLoaded('schedules'),
            'payments' => $this->whenLoaded('payments', fn() => $this->payments->map(fn($p) => [
                'id' => $p->id,
                'receipt_number' => $p->receipt_number,
                'amount' => $p->amount,
                'payment_method' => $p->payment_method,
                'payment_date' => $p->payment_date?->toDateString(),
                'collected_by' => $p->collectedBy?->name,
            ])),
            'created_by' => $this->whenLoaded('createdBy', fn() => ['id' => $this->createdBy->id, 'name' => $this->createdBy->name]),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
