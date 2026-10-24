<?php

namespace App\Http\Requests\Auth;

use App\Enums\Role;
use App\Support\PersonDetails;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
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
     * A visitor signs up either as a school administrator (who then registers
     * their organization) or as a teacher (who then asks to join one). The
     * owner role is never available at sign-up.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return PersonDetails::rules() + [
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
            'role' => ['required', 'string', Rule::in(Role::registrable())],
            // The Terms of Service and Privacy Policy have to be accepted to sign up.
            'terms' => ['accepted'],
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
        return PersonDetails::attributes() + ['terms' => 'Terms of Service and Privacy Policy'];
    }

    /**
     * Custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return PersonDetails::messages() + [
            'terms.accepted' => 'Please accept the Terms of Service and Privacy Policy to create an account.',
            'role.required' => 'Please choose whether you are registering as an administrator or as a teacher.',
            'role.in' => 'Please choose whether you are registering as an administrator or as a teacher.',
        ];
    }
}
