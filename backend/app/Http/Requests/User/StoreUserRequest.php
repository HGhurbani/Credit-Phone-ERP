<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|max:20',
            'password' => 'required|string|min:8',
            'branch_id' => 'nullable|exists:branches,id',
            'role' => 'nullable|string|exists:roles,name',
            'locale' => 'nullable|in:ar,en',
            'is_active' => 'boolean',
        ];
    }
}
