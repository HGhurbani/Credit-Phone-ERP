<?php

namespace App\Http\Requests\Cashbox;

use Illuminate\Foundation\Http\FormRequest;

class StoreManualAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'direction' => 'required|in:in,out',
            'amount' => 'required|numeric|min:0.01',
            'transaction_date' => 'required|date',
            'notes' => 'nullable|string|max:2000',
        ];
    }
}
