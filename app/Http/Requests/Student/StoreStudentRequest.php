<?php

namespace App\Http\Requests\Student;

use App\Enums\Sex;
use App\Models\GradeLevel;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudentRequest extends FormRequest
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
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'extension' => ['nullable', 'string', 'max:20'],
            'birth_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'age' => ['required', 'integer', 'min:0', 'max:120'],
            'sex' => ['required', Rule::enum(Sex::class)],
            'grade_level_id' => [
                'required',
                'integer',
                Rule::exists('grade_levels', 'id')->where('user_id', $this->user()?->id),
            ],
            'school_year_id' => [
                'nullable',
                'integer',
                Rule::exists('school_years', 'id')->where('organization_id', $this->user()?->organization_id),
            ],
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
            'school_year_id.exists' => 'The selected school year is invalid.',
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
            'birth_date' => 'birth date',
        ];
    }

    /**
     * A student's school year is the school year of their grade level; an
     * explicit school year that disagrees with the grade level is rejected.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->hasAny(['grade_level_id', 'school_year_id'])) {
                return;
            }

            $schoolYearId = $this->input('school_year_id');

            if ($schoolYearId === null || $schoolYearId === '') {
                return;
            }

            $gradeLevel = GradeLevel::query()->find((int) $this->input('grade_level_id'));

            if ($gradeLevel !== null && (int) $gradeLevel->school_year_id !== (int) $schoolYearId) {
                $validator->errors()->add(
                    'school_year_id',
                    "The school year must match the grade level's school year."
                );
            }
        });
    }

    /**
     * The validated attributes that may be persisted on a student.
     *
     * @return array<string, mixed>
     */
    public function studentData(): array
    {
        return $this->safe()->only([
            'first_name',
            'middle_name',
            'last_name',
            'extension',
            'birth_date',
            'age',
            'sex',
            'grade_level_id',
            'school_year_id',
        ]);
    }
}
