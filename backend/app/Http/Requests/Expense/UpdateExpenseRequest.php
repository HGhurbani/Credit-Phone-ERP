<?php

namespace App\Http\Requests\Expense;

use Illuminate\Foundation\Http\FormRequest;

class UpdateExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category' => 'sometimes|string|max:100',
            'vendor_name' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:2000',
            'expense_date' => 'sometimes|date',
        ];
    }
}
