<?php

namespace App\Http\Resources;

use App\Models\GoodsReceiptItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $received = (int) GoodsReceiptItem::where('purchase_order_item_id', $this->id)->sum('quantity');

        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'quantity' => (int) $this->quantity,
            'quantity_received' => $received,
            'unit_cost' => (string) $this->unit_cost,
            'total' => (string) $this->total,
            'product' => $this->whenLoaded('product', fn () => new ProductResource($this->product)),
        ];
    }
}
