<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'receipt_number' => $this->receipt_number,
            'amount' => $this->amount,
            'payment_method' => $this->payment_method,
            'payment_date' => $this->payment_date?->toDateString(),
            'reference_number' => $this->reference_number,
            'collector_notes' => $this->collector_notes,
            'customer' => $this->whenLoaded('customer', fn() => ['id' => $this->customer->id, 'name' => $this->customer->name, 'phone' => $this->customer->phone]),
            'contract' => $this->whenLoaded('contract', fn() => $this->contract ? ['id' => $this->contract->id, 'contract_number' => $this->contract->contract_number] : null),
            'schedule' => $this->whenLoaded('schedule', fn() => $this->schedule ? ['id' => $this->schedule->id, 'installment_number' => $this->schedule->installment_number, 'due_date' => $this->schedule->due_date?->toDateString()] : null),
            'collected_by' => $this->whenLoaded('collectedBy', fn() => ['id' => $this->collectedBy->id, 'name' => $this->collectedBy->name]),
            'branch' => $this->whenLoaded('branch', fn() => ['id' => $this->branch->id, 'name' => $this->branch->name]),
            'invoice' => $this->whenLoaded('invoice', fn() => $this->invoice ? ['id' => $this->invoice->id, 'invoice_number' => $this->invoice->invoice_number] : null),
            'receipt' => $this->whenLoaded('receipt'),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
