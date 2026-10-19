<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrganizationRequest extends FormRequest
{
    /**
     * Only an admin who has not registered an organization yet.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->isAdmin() && ! $user->hasOrganization();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Branches are objects ({name, address?, is_main?}); the admin flags the
     * main one (the first branch when none is flagged). The school's address
     * is the main branch's.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'contact_email' => ['nullable', 'string', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'branches' => ['required', 'array', 'min:1', 'max:20'],
            'branches.*.name' => ['required', 'string', 'max:100', 'distinct:ignore_case'],
            'branches.*.address' => ['nullable', 'string', 'max:255'],
            'branches.*.is_main' => ['nullable', 'boolean'],
            // A paid plan picked at sign-up; it becomes a plan request for the owner (the school starts on a trial either way).
            'plan_id' => ['nullable', 'integer', Rule::exists('plans', 'id')->where('is_active', true)],
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
            'branches' => 'branches',
            'branches.*.name' => 'branch name',
            'branches.*.address' => 'branch address',
            'plan_id' => 'plan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'branches.required' => 'Add at least one branch.',
            'branches.min' => 'Add at least one branch.',
            'branches.*.name.distinct' => 'Each branch needs a different name.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function organizationData(): array
    {
        return $this->safe()->only(['name', 'contact_email', 'contact_phone', 'branches', 'plan_id']);
    }
}
