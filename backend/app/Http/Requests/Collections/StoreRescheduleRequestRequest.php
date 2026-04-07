<?php

namespace App\Http\Requests\Collections;

use Illuminate\Foundation\Http\FormRequest;

class StoreRescheduleRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'contract_id' => ['required', 'integer', 'exists:installment_contracts,id'],
            'note' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
