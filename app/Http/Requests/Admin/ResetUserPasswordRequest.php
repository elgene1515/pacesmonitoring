<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ResetUserPasswordRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * An admin resets a member's password without knowing (or needing) the
     * member's current one; the admin confirms the action with their own
     * password instead. Changing one's own password through this endpoint
     * works like Settings: the current password is required.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ];

        if ($this->isSelf()) {
            $rules['current_password'] = ['required', 'string', 'current_password:web'];
        } else {
            $rules['admin_password'] = ['required', 'string', 'current_password:web'];
        }

        return $rules;
    }

    /**
     * Custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'admin_password.current_password' => 'Your password is incorrect.',
            'current_password.current_password' => 'The current password is incorrect.',
        ];
    }

    /**
     * Custom attribute names used in error messages.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'admin_password' => 'your password',
            'current_password' => 'current password',
        ];
    }

    /**
     * True when the signed-in user is changing their own password.
     */
    protected function isSelf(): bool
    {
        $target = $this->route('user');

        return $target instanceof User && $this->user()?->id === $target->id;
    }
}
