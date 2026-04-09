<?php

namespace App\Http\Requests\Cashbox;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCashboxRequest extends FormRequest
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
            'name' => 'required|string|max:255',
            'type' => 'nullable|string|max:64',
            'is_primary' => 'sometimes|boolean',
            'opening_balance' => 'nullable|numeric',
            'is_active' => 'boolean',
        ];
    }
}
