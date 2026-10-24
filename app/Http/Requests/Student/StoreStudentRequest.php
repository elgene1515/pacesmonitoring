<?php

namespace App\Http\Requests\Student;

use App\Enums\Sex;
use App\Models\Section;
use App\Models\TeachingAssignment;
use App\Support\StudentDetails;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Enroll a student in a section: one the teacher teaches in, or any section
 * of the admin's organization (platform staff may enroll anywhere).
 */
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
        $rules = $this->detailRules() + StudentDetails::rules() + [
            'section_id' => ['required', 'integer', $this->sectionRule()],
            // The subjects the student takes (default: every subject of the section's grade level).
            'subject_ids' => ['sometimes', 'array', 'max:100'],
            'subject_ids.*' => ['integer', 'distinct', 'exists:subjects,id'],
        ];

        // The ID number is unique within the school the section belongs to.
        $rules['student_number'][] = StudentDetails::uniqueNumber(
            Section::query()->find((int) $this->input('section_id')),
            $this->ignoredStudentId()
        );

        return $rules;
    }

    /**
     * The student being edited (none when enrolling).
     */
    protected function ignoredStudentId(): ?int
    {
        return null;
    }

    /**
     * The chosen subjects must be ones the section offers, and a
     * teacher has to keep at least one they teach there (otherwise the
     * student would vanish from their lists).
     *
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $ids = $this->input('subject_ids');

            if ($validator->errors()->isNotEmpty() || ! is_array($ids)) {
                return;
            }

            $ids = array_map('intval', $ids);
            $section = Section::query()->with('subjects:subjects.id')->find((int) $this->input('section_id'));
            $allowed = $section?->subjects->pluck('id')->map(fn ($id) => (int) $id)->all() ?? [];

            if (array_diff($ids, $allowed) !== []) {
                $validator->errors()->add('subject_ids', 'Pick subjects the section offers only.');

                return;
            }

            $user = $this->user();

            if ($user !== null && $user->isTeacher() && $section !== null) {
                $taught = TeachingAssignment::query()
                    ->where('section_id', $section->id)
                    ->where('teacher_id', $user->id)
                    ->pluck('subject_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                if (array_intersect($ids, $taught) === []) {
                    $validator->errors()->add('subject_ids', 'Include at least one subject you teach in that section.');
                }
            }
        }];
    }

    /**
     * The rules for a student's own details.
     *
     * @return array<string, mixed>
     */
    protected function detailRules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'extension' => ['nullable', 'string', 'max:20'],
            'birth_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'age' => ['required', 'integer', 'min:0', 'max:120'],
            'sex' => ['required', Rule::enum(Sex::class)],
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
            'section_id' => 'section',
            'birth_date' => 'birth date',
        ] + StudentDetails::attributes();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return StudentDetails::messages();
    }

    /**
     * The student's own details (everything but the section).
     *
     * @return array<string, mixed>
     */
    public function studentData(): array
    {
        return StudentDetails::clean($this->safe()->only([
            'first_name',
            'middle_name',
            'last_name',
            'extension',
            'birth_date',
            'age',
            'sex',
            ...StudentDetails::FIELDS,
        ]));
    }

    /**
     * The subjects the student takes, or null to take every subject the section offers.
     *
     * @return array<int, int>|null
     */
    public function subjectIds(): ?array
    {
        $ids = $this->validated('subject_ids');

        return is_array($ids) ? array_values(array_unique(array_map('intval', $ids))) : null;
    }

    /**
     * The section to enroll in (validated to be one the user may use).
     */
    public function section(): Section
    {
        return Section::query()->findOrFail((int) $this->validated('section_id'));
    }

    /**
     * The sections the user may enroll a student in.
     */
    protected function sectionRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $user = $this->user();

            $query = Section::query()->whereKey((int) $value);

            if ($user === null) {
                $fail('The selected section is invalid.');

                return;
            }

            if ($user->isTeacher()) {
                $query->ownedBy($user);
            } elseif (! $user->isPlatform()) {
                $query->forOrganization($user->organization_id);
            }

            if (! $query->exists()) {
                $fail($user->isTeacher()
                    ? 'You can only enroll students in a section you teach.'
                    : 'The selected section is invalid.');
            }
        };
    }
}
