<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'name_ar' => $this->name_ar,
            'sku' => $this->sku,
            'description' => $this->description,
            'cash_price' => $this->cash_price,
            'installment_price' => $this->installment_price,
            'monthly_percent_of_cash' => $this->monthly_percent_of_cash,
            'fixed_monthly_amount' => $this->fixed_monthly_amount,
            'cost_price' => $this->cost_price,
            'min_down_payment' => $this->min_down_payment,
            'allowed_durations' => $this->allowed_durations,
            'image' => $this->image ? asset('storage/' . $this->image) : null,
            'track_serial' => $this->track_serial,
            'is_active' => $this->is_active,
            'category' => $this->whenLoaded('category', fn() => ['id' => $this->category->id, 'name' => $this->category->name]),
            'brand' => $this->whenLoaded('brand', fn() => ['id' => $this->brand->id, 'name' => $this->brand->name]),
            'inventories' => $this->whenLoaded('inventories', fn() => $this->inventories->map(fn($inv) => [
                'branch_id' => $inv->branch_id,
                'branch_name' => $inv->branch?->name,
                'quantity' => $inv->quantity,
                'min_quantity' => $inv->min_quantity,
                'is_low_stock' => $inv->isLowStock(),
            ])),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
