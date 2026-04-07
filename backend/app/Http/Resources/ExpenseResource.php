<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'expense_number' => $this->expense_number,
            'category' => $this->category,
            'amount' => (string) $this->amount,
            'expense_date' => $this->expense_date?->toDateString(),
            'vendor_name' => $this->vendor_name,
            'notes' => $this->notes,
            'status' => $this->status,
            'branch' => $this->whenLoaded('branch', fn () => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
            ]),
            'cashbox' => $this->whenLoaded('cashbox', fn () => $this->cashbox ? [
                'id' => $this->cashbox->id,
                'name' => $this->cashbox->name,
            ] : null),
            'created_by' => $this->whenLoaded('createdBy', fn () => [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ]),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
