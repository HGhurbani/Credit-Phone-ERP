<?php

namespace App\Http\Requests\PurchaseOrder;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = $this->user()?->tenant_id;

        return [
            'supplier_id' => [
                'required',
                Rule::exists('suppliers', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'branch_id' => [
                'nullable',
                Rule::exists('branches', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'order_date' => 'required|date',
            'expected_date' => 'nullable|date',
            'discount_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:2000',
            'status' => 'nullable|in:draft,ordered',
            'items' => 'nullable|array',
            'items.*.product_id' => [
                'required_with:items',
                Rule::exists('products', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'items.*.quantity' => 'required_with:items.*.product_id|integer|min:1',
            'items.*.unit_cost' => 'required_with:items.*.product_id|numeric|min:0',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $data = $v->getData();
            $status = $data['status'] ?? 'draft';
            $items = $data['items'] ?? null;
            if ($status === 'ordered' && (! is_array($items) || count($items) < 1)) {
                $v->errors()->add('items', 'At least one line item is required when status is ordered.');
            }
        });
    }
}
