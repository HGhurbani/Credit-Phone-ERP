<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'type' => $this->type,
            'status' => $this->status,
            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discount_amount,
            'total' => $this->total,
            'notes' => $this->notes,
            'rejection_reason' => $this->rejection_reason,
            'approved_at' => $this->approved_at?->toDateTimeString(),
            'customer' => $this->whenLoaded('customer', fn() => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'phone' => $this->customer->phone,
            ]),
            'branch' => $this->whenLoaded('branch', fn() => ['id' => $this->branch->id, 'name' => $this->branch->name]),
            'sales_agent' => $this->whenLoaded('salesAgent', fn() => ['id' => $this->salesAgent->id, 'name' => $this->salesAgent->name]),
            'approved_by' => $this->whenLoaded('approvedBy', fn() => ['id' => $this->approvedBy->id, 'name' => $this->approvedBy->name]),
            'items' => $this->whenLoaded('items', fn() => $this->items->map(function ($item) {
                $row = [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name,
                    'product_sku' => $item->product_sku,
                    'unit_price' => $item->unit_price,
                    'quantity' => $item->quantity,
                    'discount_amount' => $item->discount_amount,
                    'total' => $item->total,
                    'serial_number' => $item->serial_number,
                ];
                if ($item->relationLoaded('product') && $item->product) {
                    $row['product'] = [
                        'cash_price' => (float) $item->product->cash_price,
                        'installment_price' => $item->product->installment_price !== null
                            ? (float) $item->product->installment_price
                            : null,
                        'min_down_payment' => $item->product->min_down_payment !== null
                            ? (float) $item->product->min_down_payment
                            : 0.0,
                        'monthly_percent_of_cash' => $item->product->monthly_percent_of_cash !== null
                            ? (float) $item->product->monthly_percent_of_cash
                            : null,
                        'fixed_monthly_amount' => $item->product->fixed_monthly_amount !== null
                            ? (float) $item->product->fixed_monthly_amount
                            : null,
                    ];
                }

                return $row;
            })),
            'contract' => $this->whenLoaded('contract', fn() => $this->contract ? ['id' => $this->contract->id, 'contract_number' => $this->contract->contract_number] : null),
            'invoice' => $this->whenLoaded('invoice', fn() => $this->invoice ? ['id' => $this->invoice->id, 'invoice_number' => $this->invoice->invoice_number] : null),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
