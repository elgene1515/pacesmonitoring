<?php

namespace App\Http\Requests\GradeLevel;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class StoreGradeLevelRequest extends FormRequest
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
            'school_year_id' => [
                'required',
                'integer',
                Rule::exists('school_years', 'id')->where('organization_id', $this->user()?->organization_id),
            ],
            'name' => ['required', 'string', 'max:100', $this->uniqueName((int) $this->input('school_year_id'))],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
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
            'school_year_id.required' => 'Please select the school year this grade level belongs to.',
            'school_year_id.exists' => 'The selected school year is invalid.',
            'name.unique' => 'You already have a grade level with this name in that school year.',
            'sort_order.min' => 'The sort order must be 0 or greater.',
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
     * Grade level names only need to be unique among the current user's levels in one school year.
     */
    protected function uniqueName(int $schoolYearId): Unique
    {
        return Rule::unique('grade_levels', 'name')
            ->where('user_id', $this->user()?->id)
            ->where('school_year_id', $schoolYearId);
    }
}
