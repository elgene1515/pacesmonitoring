<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Editing a school's details (by its admin or by the platform staff) is
 * confirmed with the signed-in user's own password.
 */
class UpdateOrganizationRequest extends FormRequest
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
        return [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact_email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'admin_password' => ['required', 'string', 'current_password:web'],
        ];
    }

    /**
     * Custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'admin_password.required' => 'Enter your password to confirm this change.',
            'admin_password.current_password' => 'Your password is incorrect.',
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
            'name' => 'school name',
            'contact_email' => 'contact email',
            'contact_phone' => 'contact phone',
            'admin_password' => 'your password',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function organizationData(): array
    {
        $data = $this->safe()->only(['name', 'address', 'contact_email', 'contact_phone']);

        if (array_key_exists('name', $data)) {
            $data['name'] = trim((string) $data['name']);
        }

        return $data;
    }
}
