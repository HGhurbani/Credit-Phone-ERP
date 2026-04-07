<?php

namespace App\Http\Requests\Invoice;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoicePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = $this->user()?->tenant_id;

        return [
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,bank_transfer,cheque,card,other',
            'payment_date' => 'nullable|date',
            'reference_number' => 'nullable|string|max:255',
            'collector_notes' => 'nullable|string|max:2000',
            'cashbox_id' => [
                'nullable',
                'integer',
                Rule::exists('cashboxes', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
        ];
    }
}
