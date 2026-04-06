<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'settings' => 'required|array',
            'settings.*' => 'nullable',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $settings = $this->input('settings', []);

            if (! is_array($settings)) {
                return;
            }

            foreach (array_keys($settings) as $key) {
                if (! is_string($key) || $key === '') {
                    $validator->errors()->add('settings', 'Setting keys must be non-empty strings.');

                    return;
                }
                if (strlen($key) > 100) {
                    $validator->errors()->add('settings', 'Setting key is too long: '.$key);

                    return;
                }
                if (! preg_match('/^[a-zA-Z0-9_.-]+$/', $key)) {
                    $validator->errors()->add('settings', 'Invalid setting key format: '.$key);

                    return;
                }
            }
        });
    }
}
