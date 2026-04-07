<?php

namespace App\Http\Requests\Collections;

use Illuminate\Foundation\Http\FormRequest;

class StorePromiseToPayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'contract_id' => ['nullable', 'integer', 'exists:installment_contracts,id'],
            'promised_amount' => ['required', 'numeric', 'min:0.01'],
            'promised_date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
