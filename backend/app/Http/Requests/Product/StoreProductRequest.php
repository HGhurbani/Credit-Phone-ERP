<?php

namespace App\Http\Requests\Product;

use App\Support\TenantSettings;
use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $mode = TenantSettings::string($this->user()->tenant_id, 'installment_pricing_mode', 'percentage');

        $base = [
            'name' => 'required|string|max:255',
            'name_ar' => 'nullable|string|max:255',
            'category_id' => 'nullable|exists:categories,id',
            'brand_id' => 'nullable|exists:brands,id',
            'sku' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'cash_price' => 'required|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'track_serial' => 'boolean',
            'is_active' => 'boolean',
        ];

        if ($mode === 'fixed') {
            return array_merge($base, [
                'fixed_monthly_amount' => 'required|numeric|min:0.01',
                'min_down_payment' => 'required|numeric|min:0',
                'allowed_durations' => 'required|array|min:1',
                'allowed_durations.*' => 'integer|min:1',
                'installment_price' => 'nullable|numeric|min:0',
                'monthly_percent_of_cash' => 'nullable|numeric|min:0|max:100',
            ]);
        }

        return array_merge($base, [
            'installment_price' => 'required|numeric|min:0',
            'min_down_payment' => 'nullable|numeric|min:0',
            'allowed_durations' => 'nullable|array',
            'allowed_durations.*' => 'integer|min:1',
            'monthly_percent_of_cash' => 'nullable|numeric|min:0|max:100',
            'fixed_monthly_amount' => 'nullable|numeric|min:0',
        ]);
    }
}
