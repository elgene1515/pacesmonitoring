<?php

namespace App\Http\Requests\SchoolYear;

use App\Models\SchoolYear;

class UpdateSchoolYearRequest extends StoreSchoolYearRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'regex:'.SchoolYear::NAME_PATTERN,
                $this->consecutiveYears(),
                $this->uniqueName()->ignore($this->route('schoolYear')),
            ],
            'start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'is_current' => ['sometimes', 'boolean'],
        ];
    }
}
