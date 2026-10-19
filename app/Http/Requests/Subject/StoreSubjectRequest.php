<?php

namespace App\Http\Requests\Subject;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSubjectRequest extends FormRequest
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
            'grade_level_id' => [
                'required',
                'integer',
                Rule::exists('grade_levels', 'id')->where('user_id', $this->user()?->id),
            ],
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('subjects', 'name')->where('grade_level_id', (int) $this->input('grade_level_id')),
            ],
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
            'grade_level_id.exists' => 'The selected grade level is invalid.',
            'name.unique' => 'This grade level already has a subject with this name.',
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
            'grade_level_id' => 'grade level',
            'sort_order' => 'sort order',
        ];
    }

    /**
     * The validated attributes that may be persisted on a subject (name, sort_order).
     *
     * @return array<string, mixed>
     */
    public function subjectData(): array
    {
        $data = $this->safe()->only(['name', 'sort_order']);
        $data['name'] = trim((string) $data['name']);

        if (array_key_exists('sort_order', $data) && $data['sort_order'] === null) {
            unset($data['sort_order']);
        }

        return $data;
    }
}
