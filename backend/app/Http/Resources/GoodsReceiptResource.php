<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GoodsReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'receipt_number' => $this->receipt_number,
            'received_at' => $this->received_at?->toDateTimeString(),
            'notes' => $this->notes,
            'branch' => $this->whenLoaded('branch', fn () => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
            ]),
            'received_by' => $this->whenLoaded('receivedBy', fn () => [
                'id' => $this->receivedBy->id,
                'name' => $this->receivedBy->name,
            ]),
            'items' => GoodsReceiptItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
