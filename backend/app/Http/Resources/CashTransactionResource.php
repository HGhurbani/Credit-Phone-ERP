<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transaction_type' => $this->transaction_type,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'amount' => (string) $this->amount,
            'direction' => $this->direction,
            'transaction_date' => $this->transaction_date?->toDateString(),
            'notes' => $this->notes,
            'voucher_number' => $this->voucher_number,
            'cashbox' => $this->whenLoaded('cashbox', fn () => [
                'id' => $this->cashbox->id,
                'name' => $this->cashbox->name,
            ]),
            'branch' => $this->whenLoaded('branch', fn () => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
            ]),
            'created_by' => $this->whenLoaded('createdBy', fn () => [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ]),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
