<?php

namespace App\Http\Requests\GradeLevel;

use App\Models\GradeLevel;

class UpdateGradeLevelRequest extends StoreGradeLevelRequest
{
    /**
     * A grade level stays in the school year it was registered under; only
     * its name and order can change.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $gradeLevel = $this->route('gradeLevel');
        $schoolYearId = $gradeLevel instanceof GradeLevel ? (int) $gradeLevel->school_year_id : 0;

        return [
            'name' => ['required', 'string', 'max:100', $this->uniqueName($schoolYearId)->ignore($gradeLevel)],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
