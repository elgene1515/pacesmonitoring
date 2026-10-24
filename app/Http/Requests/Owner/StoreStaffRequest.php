<?php

namespace App\Http\Requests\Owner;

use App\Enums\Role;
use App\Support\PersonDetails;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * The owner adds a platform staff account (another owner or a vice admin) with
 * the same details a sign-up asks for: the name in parts, a contact number and
 * an optional address, plus the email, a first password and the role. (No
 * terms acceptance: the owner creates the account, the person does not sign up.)
 */
class StoreStaffRequest extends FormRequest
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
        return PersonDetails::rules() + [
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
            'role' => ['required', 'string', Rule::in(Role::platform())],
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
     * @return array<string, string>
     */
    public function messages(): array
    {
        return PersonDetails::messages();
    }
}
