<?php

namespace App\Http\Requests\Student;

/**
 * An admin's direct edit of a student: the full details, and the section
 * (moving a student between sections of the organization).
 */
class UpdateStudentRequest extends StoreStudentRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return parent::rules();
    }

    /**
     * The admin edits the student in the route, so their own ID number is not a clash.
     */
    protected function ignoredStudentId(): ?int
    {
        $student = $this->route('student');

        return $student === null ? null : (int) (is_object($student) ? $student->getKey() : $student);
    }

    /**
     * The details plus the section.
     *
     * @return array<string, mixed>
     */
    public function studentData(): array
    {
        return parent::studentData() + ['section_id' => (int) $this->validated('section_id')];
    }
}
