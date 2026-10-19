<?php

namespace App\Http\Requests\Owner;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A destructive owner action confirmed with the owner's own password
 * (deactivating an organization, deleting a plan).
 */
class ConfirmPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'admin_password' => ['required', 'string', 'current_password:web'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'admin_password.required' => 'Enter your password to confirm.',
            'admin_password.current_password' => 'Your password is incorrect.',
        ];
    }
}
