<?php

namespace App\Http\Requests\JoinRequest;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreJoinRequestRequest extends FormRequest
{
    /**
     * Only a teacher who does not belong to an organization yet.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->isTeacher() && ! $user->hasOrganization();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'organization_code' => ['required', 'string', 'max:16'],
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')],
            'message' => ['nullable', 'string', 'max:255'],
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
            'organization_code' => 'organization code',
            'branch_id' => 'branch',
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
            'branch_id.exists' => 'The selected branch is invalid.',
        ];
    }
}
