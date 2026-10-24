<?php

namespace App\Http\Requests\TeachingAssignment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An admin gives a section + subject (from the route) to a teacher.
 */
class AssignTeacherRequest extends FormRequest
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
        return [
            'teacher_id' => ['required', 'integer', Rule::exists('users', 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['teacher_id' => 'teacher'];
    }

    public function teacherId(): int
    {
        return (int) $this->validated('teacher_id');
    }
}
