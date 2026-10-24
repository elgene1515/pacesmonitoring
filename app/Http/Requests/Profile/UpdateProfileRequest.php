<?php

namespace App\Http\Requests\Profile;

use App\Support\PersonDetails;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return PersonDetails::rules() + [
            'current_password' => ['required', 'string', 'current_password:web'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->user()?->id),
            ],
        ];
    }

    /**
     * The name parts and contact details, trimmed.
     *
     * @return array<string, string|null>
     */
    public function person(): array
    {
        return PersonDetails::clean($this->validated());
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return PersonDetails::attributes();
    }

    /**
     * Custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return PersonDetails::messages() + [
            'current_password.required' => 'Enter your current password to save these changes.',
            'current_password.current_password' => 'The current password is incorrect.',
        ];
    }
}
