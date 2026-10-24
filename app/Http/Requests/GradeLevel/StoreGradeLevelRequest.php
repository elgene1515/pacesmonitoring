<?php

namespace App\Http\Requests\GradeLevel;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * An admin registers a grade level in one of the organization's branches
 * under one of its school years.
 */
class StoreGradeLevelRequest extends FormRequest
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
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')],
            'school_year_id' => ['required', 'integer', Rule::exists('school_years', 'id')],
            'name' => [
                'required',
                'string',
                'max:100',
                $this->uniqueName((int) $this->input('branch_id'), (int) $this->input('school_year_id')),
            ],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'branch_id.required' => 'Please select the branch this grade level belongs to.',
            'branch_id.exists' => 'The selected branch is invalid.',
            'school_year_id.required' => 'Please select the school year this grade level belongs to.',
            'school_year_id.exists' => 'The selected school year is invalid.',
            'name.unique' => 'This branch already has a grade level with this name in that school year.',
            'sort_order.min' => 'The sort order must be 0 or greater.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'branch_id' => 'branch',
            'school_year_id' => 'school year',
            'sort_order' => 'sort order',
        ];
    }

    /**
     * The validated attributes that may be persisted on a grade level.
     *
     * @return array<string, mixed>
     */
    public function gradeLevelData(): array
    {
        $data = $this->safe()->only(['name', 'school_year_id', 'sort_order']);

        if (array_key_exists('name', $data)) {
            $data['name'] = trim((string) $data['name']);
        }

        if (array_key_exists('sort_order', $data) && $data['sort_order'] === null) {
            unset($data['sort_order']);
        }

        return $data;
    }

    /**
     * Grade level names are unique within a branch's school year.
     */
    protected function uniqueName(int $branchId, int $schoolYearId): Unique
    {
        return Rule::unique('grade_levels', 'name')
            ->where('branch_id', $branchId)
            ->where('school_year_id', $schoolYearId);
    }
}
