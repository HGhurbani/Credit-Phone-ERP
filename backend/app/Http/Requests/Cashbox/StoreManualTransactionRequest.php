<?php

namespace App\Http\Requests\Cashbox;

use App\Models\CashTransaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreManualTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'transaction_type' => [
                'required',
                Rule::in([
                    CashTransaction::TYPE_OTHER_IN,
                    CashTransaction::TYPE_OTHER_OUT,
                    CashTransaction::TYPE_PURCHASE_PAYMENT_OUT,
                ]),
            ],
            'amount' => 'required|numeric|min:0.01',
            'transaction_date' => 'required|date',
            'notes' => 'nullable|string|max:2000',
            'reference_type' => 'nullable|string|max:255',
            'reference_id' => 'nullable|integer|min:1',
        ];
    }
}
