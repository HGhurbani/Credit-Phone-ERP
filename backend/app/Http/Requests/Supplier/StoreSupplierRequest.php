<?php

namespace App\Http\Requests\Supplier;

use Illuminate\Foundation\Http\FormRequest;

class StoreSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'contact_person' => 'nullable|string|max:255',
            'tax_number' => 'nullable|string|max:100',
            'address' => 'nullable|string|max:2000',
            'notes' => 'nullable|string|max:5000',
            'is_active' => 'boolean',
        ];
    }
}
