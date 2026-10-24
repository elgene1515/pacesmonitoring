<?php

namespace App\Http\Requests\Student;

use App\Enums\Sex;
use App\Models\Student;
use App\Models\StudentChangeRequest;
use App\Support\StudentDetails;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A teacher proposes new details for a student; only the fields sent are
 * considered, and at least one must be.
 */
class ProposeStudentChangeRequest extends FormRequest
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
        $student = $this->route('student');
        $student = $student instanceof Student ? $student : Student::query()->find((int) $student);

        $details = StudentDetails::rules(partial: true);
        $details['student_number'][] = StudentDetails::uniqueNumber($student?->section, $student?->id);

        return $details + [
            'first_name' => ['sometimes', 'required', 'string', 'max:100'],
            'middle_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'last_name' => ['sometimes', 'required', 'string', 'max:100'],
            'extension' => ['sometimes', 'nullable', 'string', 'max:20'],
            'birth_date' => ['sometimes', 'required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'sex' => ['sometimes', 'required', Rule::enum(Sex::class)],
            'message' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->hasAny(StudentChangeRequest::CHANGEABLE_FIELDS)) {
                $validator->errors()->add('changes', 'Send at least one detail to change.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['birth_date' => 'birth date'] + StudentDetails::attributes();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return StudentDetails::messages();
    }

    /**
     * The proposed values, only for the fields that were sent.
     *
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        return $this->safe()->only(StudentChangeRequest::CHANGEABLE_FIELDS);
    }
}
