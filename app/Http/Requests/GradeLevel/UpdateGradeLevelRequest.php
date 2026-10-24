<?php

namespace App\Http\Requests\GradeLevel;

use App\Models\GradeLevel;

class UpdateGradeLevelRequest extends StoreGradeLevelRequest
{
    /**
     * A grade level stays in its branch and school year; only its name and
     * order can change.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $gradeLevel = GradeLevel::query()->find((int) $this->route('gradeLevel'));

        return [
            'name' => [
                'required',
                'string',
                'max:100',
                $this->uniqueName((int) $gradeLevel?->branch_id, (int) $gradeLevel?->school_year_id)->ignore($gradeLevel),
            ],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
