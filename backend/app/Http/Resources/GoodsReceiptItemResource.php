<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GoodsReceiptItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchase_order_item_id' => $this->purchase_order_item_id,
            'quantity' => (int) $this->quantity,
            'purchase_order_item' => $this->whenLoaded('purchaseOrderItem', function () {
                return [
                    'id' => $this->purchaseOrderItem->id,
                    'product_id' => $this->purchaseOrderItem->product_id,
                    'product' => $this->purchaseOrderItem->relationLoaded('product')
                        ? new ProductResource($this->purchaseOrderItem->product)
                        : null,
                ];
            }),
        ];
    }
}
