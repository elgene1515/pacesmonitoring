<?php

namespace App\Http\Requests\Assignment;

use App\Enums\Quarter;
use App\Models\PaceAssignment;
use App\Rules\AssignmentSchoolYear;
use App\Rules\StudentTakesSubject;
use App\Rules\TeacherTeachesSubjectInSection;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class UpdateAssignmentRequest extends FormRequest
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
        $sectionId = $this->assignment()?->student?->section_id;

        return [
            'pace_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('paces', 'id')->where('branch_id', $this->user()?->branch_id),
            ],
            'subject_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('subjects', 'id'),
                new TeacherTeachesSubjectInSection($sectionId !== null ? (int) $sectionId : null, $this->user()?->id),
                new StudentTakesSubject($this->assignment()?->student_id !== null ? (int) $this->assignment()->student_id : null),
            ],
            'quarter' => ['sometimes', 'required', 'integer', Rule::in(Quarter::values())],
            'school_year' => [
                'sometimes',
                'required',
                'string',
                'regex:'.StoreAssignmentRequest::SCHOOL_YEAR_PATTERN,
                Rule::exists('school_years', 'name')->where('organization_id', $this->user()?->organization_id),
            ],
            'issued_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'date_tested' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'score' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'remarks' => ['sometimes', 'nullable', 'string', 'max:255'],
            'retake' => ['sometimes', 'boolean'],
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
            'pace_id' => 'PACE',
            'subject_id' => 'subject',
            'school_year' => 'school year',
            'issued_date' => 'issued date',
            'date_tested' => 'date tested',
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
            'pace_id.exists' => "The selected PACE is not in your branch's catalog.",
            'school_year.regex' => 'The school year must be in the format YYYY-YYYY.',
            'school_year.exists' => "The school year is not one of your organization's school years.",
        ];
    }

    /**
     * Cross-field validation: the tested date may never precede the issued date.
     * Dates not present in the payload fall back to the stored values.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->hasAny(['issued_date', 'date_tested'])) {
                return;
            }

            $issued = $this->effectiveDate('issued_date');
            $tested = $this->effectiveDate('date_tested');

            if ($issued !== null && $tested !== null && $tested->lt($issued)) {
                $validator->errors()->add(
                    'date_tested',
                    'The date tested must be on or after the issued date.'
                );
            }

            // When the subject or school-year label changes, they must agree on one school
            // year. Fields missing from the payload fall back to the stored assignment; a
            // payload touching neither (e.g. recording a score) is left alone.
            if (! $this->hasAny(['subject_id', 'school_year'])
                || $validator->errors()->hasAny(['subject_id', 'school_year'])) {
                return;
            }

            $assignment = $this->assignment();

            if ($assignment === null) {
                return;
            }

            $subjectId = $this->has('subject_id') ? $this->input('subject_id') : $assignment->subject_id;
            $schoolYear = $this->has('school_year') ? $this->input('school_year') : $assignment->school_year;

            AssignmentSchoolYear::check(
                $validator,
                is_numeric($subjectId) ? (int) $subjectId : null,
                $schoolYear === null || $schoolYear === '' ? null : (string) $schoolYear
            );
        });
    }

    /**
     * The validated attributes that may be persisted on the assignment.
     *
     * @return array<string, mixed>
     */
    public function assignmentData(): array
    {
        return $this->safe()->only([
            'pace_id',
            'subject_id',
            'quarter',
            'school_year',
            'issued_date',
            'date_tested',
            'score',
            'remarks',
            'retake',
        ]);
    }

    /**
     * The assignment bound to the route, if any.
     */
    protected function assignment(): ?PaceAssignment
    {
        $assignment = $this->route('assignment');

        return $assignment instanceof PaceAssignment ? $assignment : null;
    }

    /**
     * Resolve a date field from the request when present, otherwise from the stored assignment.
     */
    protected function effectiveDate(string $field): ?Carbon
    {
        if ($this->has($field)) {
            $value = $this->input($field);

            if ($value === null || $value === '') {
                return null;
            }

            return Carbon::createFromFormat('Y-m-d', (string) $value)->startOfDay();
        }

        $stored = $this->assignment()?->{$field};

        return $stored instanceof Carbon ? $stored->copy()->startOfDay() : null;
    }
}
