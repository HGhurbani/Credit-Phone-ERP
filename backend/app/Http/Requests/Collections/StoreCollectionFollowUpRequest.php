<?php

namespace App\Http\Requests\Collections;

use Illuminate\Foundation\Http\FormRequest;

class StoreCollectionFollowUpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'contract_id' => ['nullable', 'integer', 'exists:installment_contracts,id'],
            'outcome' => ['required', 'in:contacted,no_answer,promise_to_pay,wrong_number,reschedule_requested,visited'],
            'next_follow_up_date' => ['nullable', 'date'],
            'priority' => ['nullable', 'in:low,normal,high'],
            'note' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
