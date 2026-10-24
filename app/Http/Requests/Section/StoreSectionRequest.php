<?php

namespace App\Http\Requests\Section;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An admin adds a section to a grade level (the grade level comes from the route).
 */
class StoreSectionRequest extends FormRequest
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
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('sections', 'name')->where('grade_level_id', (int) $this->route('gradeLevel')),
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
            'name.unique' => 'This grade level already has a section with this name.',
            'sort_order.min' => 'The sort order must be 0 or greater.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['sort_order' => 'sort order'];
    }

    /**
     * @return array<string, mixed>
     */
    public function sectionData(): array
    {
        $data = $this->safe()->only(['name', 'sort_order']);
        $data['name'] = trim((string) $data['name']);

        if (array_key_exists('sort_order', $data) && $data['sort_order'] === null) {
            unset($data['sort_order']);
        }

        return $data;
    }
}
