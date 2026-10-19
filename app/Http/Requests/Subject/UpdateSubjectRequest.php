<?php

namespace App\Http\Requests\Subject;

use App\Models\Subject;
use Illuminate\Validation\Rule;

class UpdateSubjectRequest extends StoreSubjectRequest
{
    /**
     * A subject stays under the grade level it was created in; only its name
     * and order can change.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $subject = $this->route('subject');
        $gradeLevelId = $subject instanceof Subject ? (int) $subject->grade_level_id : 0;

        return [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('subjects', 'name')->where('grade_level_id', $gradeLevelId)->ignore($subject),
            ],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
