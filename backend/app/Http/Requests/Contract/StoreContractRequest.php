<?php

namespace App\Http\Requests\Contract;

use Illuminate\Foundation\Http\FormRequest;

class StoreContractRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'order_id' => 'required|exists:orders,id',
            'down_payment' => 'required|numeric|min:0',
            'monthly_amount' => 'nullable|numeric|min:0.01',
            'duration_months' => 'required|integer|min:1|max:60',
            'start_date' => 'required|date',
            'first_due_date' => 'required|date|after_or_equal:start_date',
            'notes' => 'nullable|string|max:2000',
        ];
    }
}
