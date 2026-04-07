<?php

namespace App\Http\Requests\Expense;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = $this->user()?->tenant_id;

        return [
            'branch_id' => [
                'required',
                Rule::exists('branches', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'cashbox_id' => [
                'nullable',
                Rule::exists('cashboxes', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'category' => 'required|string|max:100',
            'amount' => 'required|numeric|min:0.01',
            'expense_date' => 'required|date',
            'vendor_name' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:2000',
        ];
    }
}
