<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchase_number' => $this->purchase_number,
            'status' => $this->status,
            'order_date' => $this->order_date?->toDateString(),
            'expected_date' => $this->expected_date?->toDateString(),
            'subtotal' => (string) $this->subtotal,
            'discount_amount' => (string) $this->discount_amount,
            'total' => (string) $this->total,
            'notes' => $this->notes,
            'vendor_bill_total' => (string) $this->total,
            'supplier' => $this->whenLoaded('supplier', fn () => new SupplierResource($this->supplier)),
            'branch' => $this->whenLoaded('branch', fn () => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
            ]),
            'created_by' => $this->whenLoaded('createdBy', fn () => [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ]),
            'items' => PurchaseOrderItemResource::collection($this->whenLoaded('items')),
            'goods_receipts' => GoodsReceiptResource::collection($this->whenLoaded('goodsReceipts')),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
