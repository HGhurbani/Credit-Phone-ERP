<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'email' => 'nullable|email|max:255',
            'national_id' => 'nullable|string|max:50',
            'id_type' => 'nullable|in:national,residency,passport',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'employer_name' => 'nullable|string|max:255',
            'monthly_salary' => 'nullable|numeric|min:0',
            'credit_score' => 'nullable|in:excellent,good,fair,poor',
            'notes' => 'nullable|string|max:2000',
        ];
    }
}
