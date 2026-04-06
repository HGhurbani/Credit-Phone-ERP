<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'contract_id' => 'required|exists:installment_contracts,id',
            // Contract + tenant match enforced in PaymentService (avoid blind exists:* checks)
            'schedule_id' => 'nullable|integer|min:1',
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,bank_transfer,cheque,card,other',
            'payment_date' => 'required|date',
            'reference_number' => 'nullable|string|max:100',
            'collector_notes' => 'nullable|string|max:1000',
        ];
    }
}
