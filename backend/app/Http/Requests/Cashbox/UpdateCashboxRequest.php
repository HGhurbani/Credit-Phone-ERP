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
            'is_active' => 'boolean',
        ];
    }
}
