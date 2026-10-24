<?php

namespace App\Http\Requests\Assignment;

use App\Enums\Quarter;
use App\Models\Student;
use App\Rules\AssignmentSchoolYear;
use App\Rules\StudentTakesSubject;
use App\Rules\TeacherTeachesSubjectInSection;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssignmentRequest extends FormRequest
{
    /**
     * School year format, e.g. 2026-2027.
     */
    public const SCHOOL_YEAR_PATTERN = '/^\d{4}-\d{4}$/';

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
        $student = $this->route('student');
        $sectionId = $student instanceof Student ? (int) $student->section_id : null;

        return [
            'subject_id' => [
                'required',
                'integer',
                Rule::exists('subjects', 'id'),
                new TeacherTeachesSubjectInSection($sectionId, $this->user()?->id),
                new StudentTakesSubject($student instanceof Student ? (int) $student->id : null),
            ],
            'pace_ids' => ['required', 'array', 'min:1'],
            'pace_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('paces', 'id')->where('branch_id', $this->user()?->branch_id),
            ],
            'school_year' => [
                'required',
                'string',
                'regex:'.self::SCHOOL_YEAR_PATTERN,
                Rule::exists('school_years', 'name')->where('organization_id', $this->user()?->organization_id),
            ],
            'quarter' => ['required', 'integer', Rule::in(Quarter::values())],
            'issued_date' => ['nullable', 'date_format:Y-m-d'],
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
            'subject_id' => 'subject',
            'pace_ids' => 'PACEs',
            'pace_ids.*' => 'PACE',
            'school_year' => 'school year',
            'issued_date' => 'issued date',
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
            'pace_ids.*.exists' => "The selected PACE is not in your branch's catalog.",
            'school_year.regex' => 'The school year must be in the format YYYY-YYYY.',
            'school_year.exists' => "The school year is not one of your organization's school years.",
        ];
    }

    /**
     * The assignment's school-year label must match the school year the
     * subject's grade level is registered under.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->hasAny(['subject_id', 'school_year'])) {
                return;
            }

            AssignmentSchoolYear::check(
                $validator,
                is_numeric($this->input('subject_id')) ? (int) $this->input('subject_id') : null,
                $this->filled('school_year') ? (string) $this->input('school_year') : null
            );
        });
    }

    /**
     * The validated attributes describing the assignment batch.
     *
     * @return array{subject_id: int, pace_ids: array<int, int>, school_year: string, quarter: int, issued_date: ?string}
     */
    public function assignmentData(): array
    {
        $data = $this->safe()->only(['subject_id', 'pace_ids', 'school_year', 'quarter', 'issued_date']);

        $data['issued_date'] = $data['issued_date'] ?? null;

        return $data;
    }
}
