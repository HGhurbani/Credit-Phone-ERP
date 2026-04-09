<?php

namespace App\Http\Requests\Cashbox;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCashboxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'type' => 'sometimes|nullable|string|max:64',
            'is_active' => 'boolean',
            'is_primary' => 'sometimes|boolean',
        ];
    }
}
