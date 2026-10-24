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
        $subject = Subject::query()->find((int) $this->route('subject'));

        return [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('subjects', 'name')->where('grade_level_id', (int) $subject?->grade_level_id)->ignore($subject),
            ],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
