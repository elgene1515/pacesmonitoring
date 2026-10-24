<?php

namespace App\Http\Requests\Section;

use App\Models\Section;
use Illuminate\Validation\Rule;

class UpdateSectionRequest extends StoreSectionRequest
{
    /**
     * A section stays under its grade level; only its name and order can change.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $section = Section::query()->find((int) $this->route('section'));

        return [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('sections', 'name')->where('grade_level_id', (int) $section?->grade_level_id)->ignore($section),
            ],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
